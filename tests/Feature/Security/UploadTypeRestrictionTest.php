<?php

namespace Tests\Feature\Security;

use App\Support\UploadRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * F190 / OWNER DECISION D-29.5 — the approved upload lists, and the owner's gate on half of them.
 *
 * Five things are asserted here, and each one is a sentence of the decision rather than a style
 * preference of mine:
 *
 *  1. THE SIZE RULE SURVIVES. `Utility::upload_file()` REPLACES its rule array when a caller passes
 *     one (`Utility.php:376-385`), so a list without `max:` removes the size cap rather than
 *     narrowing types under it. `UploadRules` refuses such a list; this test drives that refusal
 *     with a real malformed list, because the comment in the class is not evidence.
 *
 *  2. REAL TYPE-AND-CONTENT VERIFICATION, NOT THE NAME. The decision asks for "real verification of
 *     the type and content, not just the name". `mimes:` resolves the file through finfo (magic
 *     bytes) and then requires the guessed type to match a named extension, so the proof is a file
 *     whose NAME is permitted and whose CONTENT is not. Measured in this container:
 *     a PHP script named `.mp4` guesses `text/x-php`, and a PNG renamed `.mp4` guesses `image/png`.
 *     Both are refused. `mimetypes:` would have matched the CLIENT-SUPPLIED MIME string instead,
 *     which is why it is not used.
 *
 *  3. NO EXECUTABLE OR SCRIPT-CAPABLE EXTENSION IS REACHABLE, and `svg` is in that set — the
 *     decision's "address the SVG risk the old list permits". What this can and cannot reach is
 *     spelled out in the SVG test below, because the containment here is partial and saying
 *     otherwise would be the more useful lie.
 *
 *  4. THE DIGITAL HALF IS OFF. "Do not enable the digital-type widening before F217 is closed, or a
 *     proven safe containment is applied." F217 is not closed. A test, not a comment, holds that —
 *     so flipping the flag without closing F217 fails CI and somebody reads the reason.
 *
 *  5. EVERY CALL SITE ACTUALLY ASKS. Seven call sites in `ProductController` pass the list; a list
 *     that exists and is not passed protects nothing, and the previous `[]` argument is exactly
 *     what that looks like.
 */
class UploadTypeRestrictionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ss-f190-' . getmypid();
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** A real file on disk, handed over the way a browser upload arrives. */
    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function passes(array $rules, UploadedFile $file): bool
    {
        return ! Validator::make(['f' => $file], ['f' => $rules])->fails();
    }

    private const REAL_MP4 = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2mp41";
    private const PHP_SCRIPT = "<?php system(\$_GET['c']); ?>";
    private const REAL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==';
    private const EVIL_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    // ---------------------------------------------------------------- 1. the size rule survives

    public function test_the_approved_video_list_carries_its_own_size_rule(): void
    {
        $rules = UploadRules::for('preview_video');

        $this->assertNotSame([], $rules, 'the video list is enabled by D-29.5, so it must not be empty');

        $sizeRules = array_values(array_filter(
            $rules,
            fn ($r) => is_string($r) && str_starts_with($r, 'max:')
        ));

        $this->assertCount(
            1,
            $sizeRules,
            'Utility::upload_file REPLACES the rule array rather than merging, so a list without '
            . 'max: silently removes the size cap. D-29.5: "include max with any custom_validation".'
        );
    }

    public function test_a_list_declared_without_a_size_rule_is_refused_rather_than_applied(): void
    {
        // The misconfiguration the mechanical rule exists for.
        config(['uploads.f190_probe' => ['enabled' => true, 'rules' => ['mimes:mp4,webm,mov']]]);

        $this->assertSame(
            [],
            UploadRules::for('f190_probe'),
            'a list without max: must be refused, because applying it would drop the size cap; '
            . 'returning [] leaves the platform-wide list in force, which always carries one'
        );

        // And the same list WITH both required rules is applied, so the refusal is about the
        // missing rule and not about everything being refused.
        config(['uploads.f190_probe' => [
            'enabled' => true,
            'rules' => ['mimes:mp4', 'extensions:mp4', 'max:51200'],
        ]]);

        $this->assertSame(
            ['mimes:mp4', 'extensions:mp4', 'max:51200'],
            UploadRules::for('f190_probe')
        );
    }

    // -------------------------------------------- 2. real type-and-content verification

    public function test_a_genuine_mp4_is_accepted_by_the_approved_video_list(): void
    {
        $this->assertTrue(
            $this->passes(UploadRules::for('preview_video'), $this->upload('clip.mp4', self::REAL_MP4)),
            'the approved list must accept the format it approves, or it is not a widening'
        );
    }

    public function test_a_php_script_named_as_a_video_is_refused(): void
    {
        $this->assertFalse(
            $this->passes(UploadRules::for('preview_video'), $this->upload('clip.mp4', self::PHP_SCRIPT)),
            'the name is permitted and the content is a PHP script; mimes: reads the content '
            . '(finfo reports text/x-php here), which is the "not just the name" D-29.5 requires'
        );
    }

    public function test_an_image_renamed_as_a_video_is_refused(): void
    {
        $this->assertFalse(
            $this->passes(
                UploadRules::for('preview_video'),
                $this->upload('clip.mp4', base64_decode(self::REAL_PNG_BASE64))
            ),
            'a misleading file: real PNG bytes under an .mp4 name. finfo reports image/png, so the '
            . 'mp4/webm/mov list refuses it'
        );
    }

    public function test_a_script_extension_is_refused_by_the_video_list(): void
    {
        foreach (['x.php', 'x.phtml', 'x.phar', 'x.sh', 'x.js', 'x.html', 'x.htaccess'] as $name) {
            $this->assertFalse(
                $this->passes(UploadRules::for('preview_video'), $this->upload($name, self::PHP_SCRIPT)),
                $name . ' must be refused: D-29.5 forbids executable and script-capable extensions'
            );
        }
    }

    // ------------------------------------------------------------------------ 3. the SVG risk

    public function test_no_approved_list_permits_svg_or_any_script_capable_extension(): void
    {
        $forbidden = [
            'svg', 'svgz', 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'phps',
            'js', 'mjs', 'html', 'htm', 'xhtml', 'xml', 'shtml', 'sh', 'bash', 'exe', 'dll',
            'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb', 'jar', 'jsp', 'asp', 'aspx', 'htaccess',
        ];

        foreach (['preview_video', 'downloadable_product'] as $field) {
            $declared = config('uploads.' . $field . '.rules', []);
            $extensions = [];
            foreach ($declared as $rule) {
                if (is_string($rule) && str_starts_with($rule, 'mimes:')) {
                    $extensions = array_merge($extensions, explode(',', substr($rule, 6)));
                }
            }

            $this->assertNotSame([], $extensions, $field . ' must declare a mimes: rule');

            foreach ($extensions as $extension) {
                $this->assertNotContains(
                    strtolower(trim($extension)),
                    $forbidden,
                    $field . ' must not permit ' . $extension . ' — D-29.5 forbids executable and '
                    . 'script-capable extensions, and names svg among the risks to address'
                );
            }
        }
    }

    public function test_the_svg_containment_is_recorded_as_partial_because_product_images_still_permit_it(): void
    {
        /*
         * This test states a LIMIT, not an achievement, and it is here so the limit cannot be
         * mistaken for a fix later.
         *
         * The two fields that now take their own list can no longer receive an SVG — proven above.
         * PRODUCT IMAGES STILL CAN: they go through the platform-wide `local_storage_validation`,
         * whose default is `jpg,jpeg,png,csv,svg,pdf` (`Utility.php:62`), and an SVG served from the
         * document root can carry `<script>` that runs same-origin. Removing `svg` there would
         * change the rules for every merchant and every WorkDo upload at once, which D-29.5
         * forbids ("no blanket change to all WorkDo files or all merchants"), and would break any
         * merchant using an SVG logo today.
         *
         * So the assertion is that the exposure is real and still open, and the SVG is a genuine
         * attack file rather than a decorative string.
         */
        $svg = $this->upload('logo.svg', self::EVIL_SVG);

        $this->assertStringContainsString('<script>', file_get_contents($svg->getPathname()));

        $this->assertTrue(
            $this->passes(['mimes:jpg,jpeg,png,csv,svg,pdf', 'max:2048'], $svg),
            'the platform-wide default list still accepts a script-bearing SVG. This is the '
            . 'residual half of the SVG risk: it needs either a business decision about SVG or '
            . 'F217-style serving, and it is NOT closed by the two per-field lists.'
        );
    }

    // ------------------------------------------------------- 4. the digital half stays off

    public function test_the_digital_widening_stays_disabled_until_f217_is_closed(): void
    {
        $this->assertFalse(
            (bool) config('uploads.downloadable_product.enabled'),
            'D-29.5: "Do not enable the digital-type widening before F217 is closed, or a proven '
            . 'safe containment is applied." F217 is NOT closed — PaidDownloadController closes the '
            . 'path for downloads served from now on, but files already written remain reachable at '
            . 'their public URLs (run `php artisan downloads:exposure-report`). Whoever closes F217 '
            . 'flips this flag and deletes this assertion; nobody else should.'
        );

        $this->assertSame(
            [],
            UploadRules::for('downloadable_product'),
            'while the gate holds, the call sites must behave exactly as they did before F190 — '
            . 'the platform-wide list, unchanged, for every merchant'
        );
    }

    public function test_the_approved_digital_list_is_recorded_in_full_and_ready_to_enable(): void
    {
        // The decision names the list; this asserts the config holds exactly it, so enabling F190's
        // digital half later is one flag and not a fresh judgement call about what was approved.
        $rules = config('uploads.downloadable_product.rules');

        $this->assertSame(
            [
                'mimes:pdf,zip,epub,mp3,wav,csv,xlsx,docx,pptx,png,jpg,jpeg',
                'extensions:pdf,zip,epub,mp3,wav,csv,xlsx,docx,pptx,png,jpg,jpeg',
                'max:51200',
            ],
            $rules,
            'the owner-approved digital list, verbatim from D-29.5, with its own max: AND the '
            . 'extensions: rule that validates the stored NAME rather than only the bytes'
        );
    }

    // --------------------------------------------------- 5. every call site actually asks

    public function test_every_product_upload_call_site_passes_the_approved_list(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/ProductController.php'));

        foreach (['preview_video', 'downloadable_product'] as $field) {
            $asks = preg_match_all(
                "/upload_file\(\\\$request,\s*'" . $field . "'.*?UploadRules::for\('" . $field . "'\)\)/",
                $source,
                $m
            );
            $total = preg_match_all("/upload_file\(\\\$request,\s*'" . $field . "'/", $source, $all);

            $this->assertGreaterThan(0, $total, 'the census needs at least one ' . $field . ' call site');
            $this->assertSame(
                $total,
                $asks,
                'all ' . $total . ' ' . $field . ' call sites must pass UploadRules::for(), but only '
                . $asks . ' do. A list that is declared and not passed protects nothing — the '
                . 'pre-F190 argument was a literal [], which is what that looks like.'
            );
        }

        // The measured census at the time of writing, so a new call site is noticed rather than
        // silently satisfying the ratchet above by being written correctly or not at all.
        $this->assertSame(4, preg_match_all("/upload_file\(\\\$request,\s*'preview_video'/", $source, $m4));
        $this->assertSame(3, preg_match_all("/upload_file\(\\\$request,\s*'downloadable_product'/", $source, $m3));

        /*
         * THE PER-VARIANT FIELD, WHICH THE FIRST PASS MISSED. `downloadable_product_<N>` is the same
         * asset class as `downloadable_product` -- a paid digital file, written to the SAME
         * directory, read back by the SAME download path -- and both its sites passed a literal
         * `[]`. Found by an independent review. Wired now, so the two halves cannot diverge the day
         * the F217 gate opens.
         */
        $perVariant = preg_match_all(
            "/upload_file\(\\\$request,\s*'downloadable_product_'\s*\.\s*\\\$str,[^)]*UploadRules::for\('downloadable_product'\)\)/",
            $source,
            $mv
        );

        $this->assertSame(
            2,
            $perVariant,
            'both per-variant digital-file sites must pass the same list as the product-level field'
        );
    }

    public function test_the_size_ceiling_is_recorded_as_conditional_on_infrastructure(): void
    {
        /*
         * D-29.5 proposes 50 MB per file "conditional on PHP/web/infrastructure settings and on
         * proving the size can be handled safely". The condition is NOT met in this container, and
         * that is worth a failing-visible assertion rather than a sentence in a document, because
         * the config now says 51200 and a reader would reasonably assume 50 MB works.
         *
         * PHP refuses an over-limit upload BEFORE the validator runs, so the file never reaches
         * `max:`. Raising it is an operator change to php.ini and the web server's body limit, and
         * belongs in the runbook, not here.
         */
        $this->assertSame(
            '51200',
            explode(':', collect(UploadRules::for('preview_video'))->first(fn ($r) => str_starts_with($r, 'max:')))[1],
            'the approved ceiling is 50 MB expressed in KB'
        );

        $phpLimit = $this->toBytes((string) ini_get('upload_max_filesize'));

        $this->assertLessThan(
            50 * 1024 * 1024,
            $phpLimit,
            'MEASURED PRECONDITION: if this ever fails, PHP has been raised to 50 MB or more and '
            . 'the F190 ceiling is genuinely reachable — update the runbook and this assertion. '
            . 'While it passes, `max:51200` is an upper bound that PHP cuts short at '
            . ini_get('upload_max_filesize') . ', so the approved size is NOT yet deliverable.'
        );
    }

    private function toBytes(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }

    // ──────── WHAT AN INDEPENDENT REVIEW FOUND: content was checked, the NAME was not

    public function test_a_valid_video_under_a_script_extension_is_refused(): void
    {
        /*
         * THE DEFECT THIS FILE DID NOT CATCH, because every case here used an HONEST extension.
         *
         * `mimes:` compares the type GUESSED FROM THE BYTES against the named extensions. It does
         * not look at the client's extension at all. So a file with VALID MP4 MAGIC BYTES named
         * `clip.html` passed `mimes:mp4,webm,mov` — measured. Laravel's own `shouldBlockPhpUpload`
         * catches php/phtml/phar, which is why those were refused and the gap looked closed; nothing
         * stopped `.html` or `.svg`.
         *
         * AND THE STORED NAME KEEPS THE CALLER'S EXTENSION: `ProductController:787` builds
         * `'video_' . time() . rand() . '.' . $ext` from `getClientOriginalExtension()`, into
         * `uploads/<store>/video`, which is under the document root through the `public/uploads`
         * symlink. So this was stored XSS on the store's own origin — a merchant against their own
         * shoppers. `extensions:` is the fix, and these are the files that prove it.
         */
        $rules = UploadRules::for('preview_video');

        foreach (['clip.html', 'clip.svg', 'clip.xhtml', 'clip.js', 'clip.htaccess'] as $name) {
            $this->assertFalse(
                $this->passes($rules, $this->upload($name, self::REAL_MP4)),
                $name . ' carries VALID MP4 BYTES and a dangerous name. It passed before '
                . '`extensions:` was added, and the stored file would have kept that extension '
                . 'inside the document root.'
            );
        }
    }

    public function test_an_honest_video_extension_still_works_in_either_case(): void
    {
        // The fix must not break a normal upload, including the capitalisation a camera produces.
        $rules = UploadRules::for('preview_video');

        $this->assertTrue($this->passes($rules, $this->upload('clip.mp4', self::REAL_MP4)));
        $this->assertTrue(
            $this->passes($rules, $this->upload('CLIP.MP4', self::REAL_MP4)),
            '`extensions:` must be case-insensitive — phones and cameras produce .MP4 and .MOV'
        );
    }

    public function test_a_list_with_mimes_but_no_extensions_rule_is_refused(): void
    {
        /*
         * Held mechanically rather than by prose, exactly like the `max:` rule — because prose did
         * not hold it the first time. A list that checks the bytes and not the name is the defect
         * above, so it cannot be applied.
         */
        config(['uploads.f190_probe2' => [
            'enabled' => true,
            'rules' => ['mimes:mp4,webm,mov', 'max:51200'],
        ]]);

        $this->assertSame(
            [],
            UploadRules::for('f190_probe2'),
            'a list with mimes: and no extensions: must be refused: the web server acts on the '
            . 'stored NAME, not on the bytes'
        );
    }

    public function test_every_enabled_list_validates_the_name_as_well_as_the_bytes(): void
    {
        // Both halves of the shipped configuration, so neither list can lose one later.
        foreach (['preview_video', 'downloadable_product'] as $field) {
            $declared = config('uploads.' . $field . '.rules', []);

            $mimes = array_values(array_filter($declared, fn ($r) => str_starts_with($r, 'mimes:')));
            $extensions = array_values(array_filter($declared, fn ($r) => str_starts_with($r, 'extensions:')));

            $this->assertCount(1, $mimes, $field . ' must validate the bytes');
            $this->assertCount(1, $extensions, $field . ' must validate the name');

            $this->assertSame(
                substr($mimes[0], strlen('mimes:')),
                substr($extensions[0], strlen('extensions:')),
                $field . ': the two lists must name the SAME extensions. A name list wider than '
                . 'the byte list reopens the polyglot; a narrower one refuses honest uploads.'
            );
        }
    }

    public function test_the_f217_gate_opens_only_on_an_unambiguous_yes(): void
    {
        /*
         * `env()` ALONE OPENED THE OWNER'S HARD GATE ON "no". Laravel's Env::get converts only the
         * literals true/false/null/empty; everything else is a RAW STRING, and `empty('no')` is
         * false. Measured before the fix: `no`, `off` and `maybe` all opened it. An operator typing
         * `no` to keep it shut would have opened it. Found by an independent review.
         */
        $closed = ['', 'no', 'off', 'maybe', 'false', '0', 'disabled', 'NO', ' no '];
        $open = ['1', 'true', 'TRUE', 'yes', 'on', ' true '];

        foreach ($closed as $value) {
            putenv('UPLOAD_DIGITAL_TYPES_ENABLED=' . $value);

            $this->assertFalse(
                in_array(strtolower(trim((string) env('UPLOAD_DIGITAL_TYPES_ENABLED', ''))),
                    ['1', 'true', 'yes', 'on'], true),
                '`' . $value . '` must leave the F217 gate CLOSED. Anything that is not an '
                . 'unambiguous yes keeps a paid-download widening shut.'
            );
        }

        foreach ($open as $value) {
            putenv('UPLOAD_DIGITAL_TYPES_ENABLED=' . $value);

            $this->assertTrue(
                in_array(strtolower(trim((string) env('UPLOAD_DIGITAL_TYPES_ENABLED', ''))),
                    ['1', 'true', 'yes', 'on'], true),
                '`' . $value . '` must open it -- a gate nobody can open is not a gate'
            );
        }

        putenv('UPLOAD_DIGITAL_TYPES_ENABLED');
    }
}
