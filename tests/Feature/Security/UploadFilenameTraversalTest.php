<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * NEW-upload-filename-traversal.
 *
 * Every upload in this application routes through
 * Utility::upload_file($request, $key, $name, $path) /
 * Utility::keyWiseUpload_file(...), which ends in
 *
 *   Storage::disk(...)->putFileAs($path, $image, $name)
 *
 * (app/Models/Utility.php:383-387). `$name` is built by the CALLER, and 17 call
 * sites in the files this agent owns built it straight out of
 * getClientOriginalName() -- an attacker-controlled string.
 *
 * THE THREE MEASUREMENTS THAT MATTER, all taken in this container rather than
 * assumed. They are what this test encodes, and they are also why the finding
 * is "cross-tenant and in-tree file write" rather than the RCE it first looked
 * like:
 *
 *  1. Flysystem 3.30.2 does NOT reject traversal that stays inside the disk
 *     root. putFileAs('uploads/7', $f, '../../escaped.txt') returned
 *     'uploads/7/../../escaped.txt' and the file landed at the disk root. Only
 *     escaping ABOVE the root throws League\Flysystem\PathTraversalDetected.
 *
 *  2. The `theme` disk's root is base_path('') -- config/filesystems.php:47-51.
 *     So "inside the disk root" means anywhere in the PROJECT TREE, including
 *     another store's uploads/<store_id>/ directory.
 *
 *  3. Laravel's `mimes` rule DOES block a .php client extension even when the
 *     bytes are a genuine image. Measured with a real PNG byte stream named
 *     shell.php: getClientOriginalExtension()='php', guessExtension()='png',
 *     getMimeType()='image/png', and mimes:png,jpg,jpeg STILL failed
 *     ("The f must be a file of type: png, jpg, jpeg."). So this is NOT a
 *     route to dropping executable PHP, and it is not reported as one.
 *
 * The repair is basename() at the point the untrusted name enters, which is
 * where InstallAddonRequest::safeDirectoryName() already puts it for the addon
 * installer (app/Http/Requests/InstallAddonRequest.php:169).
 *
 * The central fix belongs in Utility::upload_file, so that call sites cannot
 * reintroduce this one at a time. app/Models/Utility.php is owned by another
 * agent and is reported as a cross-agent dependency; these 17 sites are
 * defence at the entry point regardless of whether that lands.
 */
class UploadFilenameTraversalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Measurement 1 and 2, pinned. If a future Flysystem or a changed disk root
     * made traversal impossible, this test tells us the underlying hazard is
     * gone instead of leaving a stale comment claiming it is still there.
     */
    public function test_the_storage_layer_still_permits_in_root_traversal(): void
    {
        \Storage::fake('probe');

        $stored = \Storage::disk('probe')->putFileAs(
            'uploads/7',
            UploadedFile::fake()->create('x.txt', 1),
            '../../escaped.txt'
        );

        $this->assertSame(
            'uploads/7/../../escaped.txt',
            $stored,
            'Flysystem now normalises or rejects in-root traversal. If so, the '
            . 'basename() guards asserted below are belt-and-braces rather than '
            . 'load-bearing -- which is worth knowing, so update the comments '
            . 'rather than deleting the guards.'
        );

        $this->assertContains(
            'escaped.txt',
            \Storage::disk('probe')->allFiles(),
            'The file did not escape its intended directory.'
        );
    }

    /**
     * And escaping ABOVE the disk root is what Flysystem does stop -- recorded
     * so the finding is not overstated.
     */
    public function test_escaping_above_the_disk_root_is_rejected(): void
    {
        \Storage::fake('probe');

        $this->expectException(\League\Flysystem\PathTraversalDetected::class);

        \Storage::disk('probe')->putFileAs(
            'uploads/7',
            UploadedFile::fake()->create('x.txt', 1),
            '../../../../../../../../tmp/escaped.txt'
        );
    }

    /**
     * Measurement 3, pinned, because it BOUNDS the severity. If a future
     * Laravel stopped blocking .php client extensions in `mimes`, this fix
     * would no longer be sufficient on its own and the finding would need
     * re-rating -- this test is what would say so.
     */
    public function test_laravel_still_blocks_a_php_filename_even_for_real_image_bytes(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        );

        $tmp = tempnam(sys_get_temp_dir(), 'traversal') . '.php';
        file_put_contents($tmp, $png);

        $file = new UploadedFile($tmp, 'shell.php', 'image/png', null, true);

        // The bytes really are a PNG.
        $this->assertSame('png', $file->guessExtension());
        $this->assertSame('php', $file->getClientOriginalExtension());

        $validator = \Validator::make(['f' => $file], ['f' => ['mimes:png,jpg,jpeg']]);

        $this->assertTrue(
            $validator->fails(),
            'Laravel no longer blocks a .php client extension in the mimes rule. '
            . 'Combined with the traversal above, an upload could then place '
            . 'executable PHP inside the project root -- re-rate this finding.'
        );

        @unlink($tmp);
    }

    /**
     * THE FIX ITSELF: no upload call site in the files this agent owns may feed
     * a raw getClientOriginalName() into the stored filename.
     *
     * A source assertion rather than an HTTP one, deliberately. The 17 sites
     * sit behind authenticated multipart endpoints across a 7,000-line
     * controller; exercising each over HTTP would need 17 fixtures and would
     * still not prove the 18th is guarded. This cannot be satisfied by adding
     * an exception anywhere.
     */
    public function test_no_owned_upload_site_uses_a_raw_client_filename(): void
    {
        $offenders = [];

        foreach ([
            'app/Http/Controllers/Api/DashboardController.php',
            'app/Http/Controllers/UserController.php',
            'app/Http/Controllers/OrderNoteController.php',
            'app/Http/Controllers/StoreFinanceController.php',
            'app/Http/Controllers/RoleController.php',
            'app/Http/Controllers/StoreController.php',
            'app/Http/Controllers/CustomerController.php',
        ] as $relative) {
            $path = base_path($relative);

            if (!file_exists($path)) {
                continue;
            }

            foreach (file($path) as $i => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '//')
                    || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (!str_contains($line, 'getClientOriginalName()')) {
                    continue;
                }

                if (!str_contains($line, 'basename(')) {
                    $offenders[] = $relative . ':' . ($i + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These upload sites build the stored filename from the attacker-supplied\n"
            . "client filename, which reaches Storage::putFileAs() as the \$name and is\n"
            . "a path-traversal sink (see this class's docblock for the measurements):\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * And basename() actually neutralises the payloads -- so the guard above is
     * asserted to WORK, not merely to be present.
     */
    public function test_basename_neutralises_the_traversal_payloads(): void
    {
        foreach ([
            '../../escaped.png' => 'escaped.png',
            '../../../../etc/passwd' => 'passwd',
            'uploads/9/../../x.png' => 'x.png',
            'ordinary-name.png' => 'ordinary-name.png',
        ] as $payload => $expected) {
            $this->assertSame(
                $expected,
                basename($payload),
                "basename() did not reduce {$payload} to a bare filename."
            );
        }

        // The real shape at the call sites: a prefix plus the basename. The
        // prefix also means a name of exactly '..' cannot form a traversal
        // segment on its own.
        $name = rand(10, 100) . '_' . time() . '_' . basename('../../shell.png');

        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString('/', $name);
    }
}
