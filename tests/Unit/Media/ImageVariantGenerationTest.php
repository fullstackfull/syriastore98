<?php

namespace Tests\Unit\Media;

use App\Services\ImageVariants;
use Tests\TestCase;

/**
 * THE VARIANTS ARE REALLY WRITTEN, REALLY SMALLER, AND REALLY WEBP.
 * =================================================================================================
 *
 * GAP-274, pipeline half. `ImageVariantPathTest` pins where a variant MAY go; this one executes
 * `gd` and reads the files back.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHY THERE IS NO `Storage::fake()` HERE, AND WHY THAT IS NOT AN OVERSIGHT
 * ------------------------------------------------------------------------------------------------
 *
 * MEASURED, not assumed: the code under test never goes through the `Storage` facade.
 * `App\Services\StoredUpload::resolve()` -- the confinement every write passes through -- calls
 * `realpath(base_path($path))` and `is_file()` directly, and `gd` reads and writes with ordinary
 * file functions. So `Storage::fake('theme')` would swap a disk nothing in this path consults, the
 * fixture would land in `storage/framework/testing/disks/theme/` where `base_path()` cannot see it,
 * and every assertion below would be satisfied by "the original was not found" -- the shape
 * CLAUDE.md §3 calls a test that passes for the wrong reason. The fixture therefore writes REAL
 * files under `base_path('uploads')`, which is where the `theme` disk's root (`base_path('')`)
 * really puts them, in a store folder no store owns, and removes them again.
 *
 * `setUp()` asserts the folder did not already exist and `tearDown()` asserts it is gone, so a
 * leftover from a crashed run cannot make a later run read someone else's files.
 */
class ImageVariantGenerationTest extends TestCase
{
    /** A store id no store has. The folder has to be a run of digits in canonical spelling or no variant is ever written. */
    private const STORE = 987654;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = base_path('uploads/' . self::STORE);

        $this->assertDirectoryDoesNotExist(
            $this->folder,
            'premise: uploads/' . self::STORE . ' must not exist before this test, or the files read '
            . 'back below could be a previous run\'s'
        );

        mkdir($this->folder, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeFolder();

        parent::tearDown();
    }

    private function removeFolder(): void
    {
        if (! is_dir($this->folder)) {
            return;
        }

        foreach (['variants', ''] as $sub) {
            $dir = rtrim($this->folder . '/' . $sub, '/');

            if (! is_dir($dir)) {
                continue;
            }

            foreach ((array) glob($dir . '/{,.}*', GLOB_BRACE) as $entry) {
                if (is_file($entry)) {
                    unlink($entry);
                }
            }

            @rmdir($dir);
        }
    }

    /** Writes a solid-colour image of the given size and returns its stored path. */
    private function original(int $width, int $height, string $extension = 'png', string $name = 'photo'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 180, 120, 60));

        $relative = 'uploads/' . self::STORE . '/' . $name . '.' . $extension;

        match ($extension) {
            'png' => imagepng($image, base_path($relative)),
            'jpg' => imagejpeg($image, base_path($relative), 90),
            'gif' => imagegif($image, base_path($relative)),
            'webp' => imagewebp($image, base_path($relative)),
        };

        imagedestroy($image);

        $this->assertFileExists(base_path($relative), 'premise: the fixture original was written where base_path() can see it');

        return $relative;
    }

    public function test_the_host_can_write_webp(): void
    {
        /* The premise the whole slice rests on, asserted rather than assumed: without it every
         * other case here would be satisfied by "nothing was written". */
        $this->assertTrue(function_exists('imagewebp'), 'premise: gd can write WebP');
        $this->assertTrue((bool) (gd_info()['WebP Support'] ?? false), 'premise: this gd build has WebP support');

        /* AVIF IS NOT ASSERTED EITHER WAY, DELIBERATELY. `imageavif()` does not exist on this
         * host -- measured, and the reason AVIF is out of scope -- but a CI runner or a future PHP
         * build may well have it, and a test that FAILS on a better host would be a red required
         * gate for a non-defect, which CLAUDE.md §3 forbids parking in the known-outcomes file. The
         * measurement lives in ImageVariants' docblock and in GAP-274 instead. */
    }

    public function test_every_rung_below_the_original_is_written_and_none_above_it(): void
    {
        $original = $this->original(1000, 750);

        $written = ImageVariants::generate($original, self::STORE);

        $this->assertSame([160, 320, 480, 768], array_keys($written), 'every rung under 1000 and no rung over it');

        foreach ($written as $width => $relative) {
            $this->assertFileExists(base_path($relative));

            $info = getimagesize(base_path($relative));

            $this->assertNotFalse($info);
            $this->assertSame($width, $info[0], "the {$width}w variant is not {$width} pixels wide");
            $this->assertSame(IMAGETYPE_WEBP, $info[2], "the {$width}w variant is not a WebP");
            // 1000x750 is 4:3, so the height follows the width rather than being left at the source's
            $this->assertSame((int) round($width * 0.75), $info[1], "the {$width}w variant does not keep the source's aspect ratio");
        }
    }

    public function test_the_original_is_not_touched(): void
    {
        $original = $this->original(1000, 750);
        $before = ['bytes' => file_get_contents(base_path($original)), 'size' => filesize(base_path($original))];

        ImageVariants::generate($original, self::STORE);

        $this->assertFileExists(base_path($original), 'the original must still be there: a merchant\'s content references its path');
        $this->assertSame($before['size'], filesize(base_path($original)), 'the original\'s size changed');
        $this->assertSame($before['bytes'], file_get_contents(base_path($original)), 'the original\'s bytes changed');
    }

    public function test_an_original_narrower_than_the_smallest_rung_gets_nothing(): void
    {
        $original = $this->original(120, 90);

        $this->assertSame([], ImageVariants::generate($original, self::STORE), 'upscaling adds bytes and no detail');
        $this->assertDirectoryDoesNotExist($this->folder . '/variants', 'nothing should have been created at all');
    }

    public function test_generation_is_idempotent_and_does_not_rewrite_what_is_there(): void
    {
        $original = $this->original(1000, 750);

        $first = ImageVariants::generate($original, self::STORE);
        $this->assertNotSame([], $first, 'premise: the first run wrote something');

        $stamps = [];
        foreach ($first as $width => $relative) {
            // A past mtime that a rewrite would move. Explicit rather than relying on clock skew.
            touch(base_path($relative), 1600000000);
            $stamps[$width] = filemtime(base_path($relative));
        }

        $second = ImageVariants::generate($original, self::STORE);

        $this->assertSame(array_keys($first), array_keys($second));

        foreach ($stamps as $width => $stamp) {
            $this->assertSame($stamp, filemtime(base_path($first[$width])), "the {$width}w variant was rewritten on a second run");
        }
    }

    public function test_existing_reports_only_what_is_on_disk(): void
    {
        $original = $this->original(1000, 750);

        $this->assertSame([], ImageVariants::existing($original, self::STORE), 'premise: nothing exists before generation');

        ImageVariants::generate($original, self::STORE);

        $this->assertSame([160, 320, 480, 768], array_keys(ImageVariants::existing($original, self::STORE)));

        unlink(base_path(ImageVariants::relativePath($original, 320, self::STORE)));

        $this->assertSame([160, 480, 768], array_keys(ImageVariants::existing($original, self::STORE)), 'a deleted variant must stop being advertised');
    }

    public function test_a_jpeg_and_a_webp_source_are_both_accepted(): void
    {
        foreach (['jpg', 'webp'] as $extension) {
            $original = $this->original(900, 900, $extension, 'src-' . $extension);

            $this->assertSame(
                [160, 320, 480, 768],
                array_keys(ImageVariants::generate($original, self::STORE)),
                "a .{$extension} source produced no variants"
            );
        }
    }

    public function test_an_animated_gif_keeps_its_original_only(): void
    {
        /* gd decodes one frame, so a variant of an animated GIF is a STILL picture -- and because
         * the <source> makes the variant the copy that paints, generating one would silently stop a
         * merchant's animation. The two-frame fixture is built by hand because gd cannot write one. */
        $relative = 'uploads/' . self::STORE . '/animation.gif';
        file_put_contents(base_path($relative), $this->twoFrameGif());

        $info = getimagesize(base_path($relative));
        $this->assertNotFalse($info, 'premise: the hand-built fixture is a readable GIF');
        $this->assertSame(IMAGETYPE_GIF, $info[2]);
        $this->assertSame(400, $info[0], 'premise: it is wider than a rung, so width is not the reason it is skipped');

        $this->assertSame([], ImageVariants::generate($relative, self::STORE));
        $this->assertDirectoryDoesNotExist($this->folder . '/variants');
    }

    public function test_a_still_gif_of_the_same_size_does_get_variants(): void
    {
        /* The control for the case above: without it, "no variants" would be satisfied by anything
         * at all refusing a GIF, and the animation check would not be what the test measured. */
        $original = $this->original(400, 300, 'gif', 'still');

        $this->assertSame([160, 320], array_keys(ImageVariants::generate($original, self::STORE)));
    }

    public function test_a_rotating_exif_tag_is_applied_so_the_variant_is_not_sideways(): void
    {
        /* A browser rotates a JPEG by its EXIF Orientation; gd does not, and imagewebp() writes no
         * EXIF. So an unrotated variant of an orientation-6 photo paints SIDEWAYS where the original
         * paints upright -- a regression caused by this feature. The fixture is a 1200x600 landscape
         * JPEG tagged orientation 6 (rotate 90 clockwise), which a viewer shows as 600x1200: the
         * variants must be portrait, and the no-upscale rule must compare against the DISPLAYED
         * width of 600, not the stored 1200. */
        $relative = 'uploads/' . self::STORE . '/rotated.jpg';
        $image = imagecreatetruecolor(1200, 600);
        imagefilledrectangle($image, 0, 0, 1199, 599, imagecolorallocate($image, 20, 140, 90));
        imagejpeg($image, base_path($relative), 92);
        imagedestroy($image);

        file_put_contents(base_path($relative), $this->withOrientationSix(file_get_contents(base_path($relative))));

        $this->assertTrue(function_exists('exif_read_data'), 'premise: the exif extension is loaded');
        $exif = @exif_read_data(base_path($relative));
        $this->assertSame(6, (int) ($exif['Orientation'] ?? 0), 'premise: the fixture really carries orientation 6');

        $written = ImageVariants::generate($relative, self::STORE);

        $this->assertSame([160, 320, 480], array_keys($written), 'the displayed width is 600, so 768 and 1280 are upscales');

        foreach ($written as $width => $path) {
            $info = getimagesize(base_path($path));
            $this->assertNotFalse($info);
            $this->assertSame($width, $info[0]);
            $this->assertGreaterThan($info[0], $info[1], "the {$width}w variant is landscape, so the EXIF rotation was not applied");
        }
    }

    public function test_an_image_type_gd_cannot_decode_is_refused_before_anything_is_created(): void
    {
        /* THIS CASE EXISTS BECAUSE A MUTATION SURVIVED WITHOUT IT. Deleting the
         * `! isset(self::DECODERS[...])` half of the type check left
         * `test_a_file_that_is_not_an_image_is_refused` green, because `getimagesize()` answers
         * FALSE for that fixture and the other half of the same `if` caught it. The two halves ask
         * different questions, and the input that separates them is a file `getimagesize()`
         * RECOGNISES and `gd` cannot decode: an ICO. Without the check it reaches `write()`, the
         * `DECODERS` lookup throws, the catch swallows it -- and the only trace is an EMPTY
         * `variants/` directory that `variantDirectory()` created on the way. So the directory is
         * what the assertion is about; "no variants were written" is true either way. */
        $relative = 'uploads/' . self::STORE . '/favicon.ico';
        $width = 200;
        $dib = pack('VVVvvVVVVVV', 40, $width, $width * 2, 1, 32, 0, $width * $width * 4, 0, 0, 0, 0)
            . str_repeat("\xFF", $width * $width * 4)
            . str_repeat("\x00", (int) (($width * $width) / 8));
        file_put_contents(
            base_path($relative),
            pack('vvv', 0, 1, 1) . pack('CCCCvvVV', $width, $width, 0, 0, 1, 32, strlen($dib), 22) . $dib
        );

        $info = getimagesize(base_path($relative));

        $this->assertNotFalse($info, 'premise: getimagesize RECOGNISES this file, which is the whole point of the case');
        $this->assertSame(IMAGETYPE_ICO, $info[2]);
        $this->assertSame($width, $info[0], 'premise: it is wider than a rung, so width is not the reason it is skipped');

        $this->assertSame([], ImageVariants::generate($relative, self::STORE));
        $this->assertDirectoryDoesNotExist(
            $this->folder . '/variants',
            'an undecodable type got as far as creating a variants directory, so the type check did not refuse it'
        );
    }

    public function test_a_source_that_would_not_fit_in_memory_is_skipped_rather_than_fatal(): void
    {
        /* THE ONE FAILURE MODE HERE A try/catch CANNOT CONTAIN. Exceeding `memory_limit` is a FATAL
         * error, so without this budget a large enough source would take the whole PRODUCT SAVE
         * down rather than skipping the picture -- and the platform's upload ceiling does not bound
         * the pixel count: the default is 2048 KB and a heavily compressed JPEG of that size can be
         * tens of megapixels.
         *
         * The limit is lowered to just above what this process is already using rather than to a
         * small absolute figure, because setting it BELOW current usage would fatal the test run
         * itself on its next allocation. */
        $original = $this->original(2000, 1500);      // 3 Mpx -> 36 MB of gd budget at 4 bytes x 3
        $previous = ini_get('memory_limit');

        try {
            ini_set('memory_limit', (memory_get_usage(true) + 8 * 1024 * 1024) . '');

            $this->assertSame([], ImageVariants::generate($original, self::STORE));
            $this->assertDirectoryDoesNotExist($this->folder . '/variants', 'nothing should have been created');
        } finally {
            ini_set('memory_limit', (string) $previous);
        }

        /* The control, with the limit back: the same file DOES get variants, so "nothing happened"
         * above is the budget and not an unreadable fixture. */
        $this->assertSame([160, 320, 480, 768, 1280], array_keys(ImageVariants::generate($original, self::STORE)));
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $relative = 'uploads/' . self::STORE . '/notes.png';
        file_put_contents(base_path($relative), "<?php echo 'not an image';");

        $this->assertFileExists(base_path($relative), 'premise: the file is there, so "no variants" is not "no file"');
        $this->assertSame([], ImageVariants::generate($relative, self::STORE));
    }

    public function test_nothing_is_written_for_another_store(): void
    {
        $original = $this->original(1000, 750);

        $this->assertSame(
            [],
            ImageVariants::generate($original, self::STORE + 1),
            'store ' . (self::STORE + 1) . ' must not be able to write into store ' . self::STORE . "'s folder"
        );
        $this->assertDirectoryDoesNotExist($this->folder . '/variants');
    }

    public function test_nothing_is_written_when_the_owning_store_cannot_be_determined(): void
    {
        /* A WRITE NEEDS A KNOWN OWNER; A READ DOES NOT. With a null store id the path rule asks only
         * "is this A store folder", so without this guard a row whose owner cannot be determined --
         * a `ProductImage` with no `store_id` whose product has none either, which
         * `StoredPathPolicy` admits with ANY store's folder because it is handed the same null --
         * could have variants written into a folder nobody proved belongs to the saver.
         *
         * The read side must keep working, or an installation that cannot resolve a store would
         * lose the variants it already has as well, so both halves are asserted here. */
        $original = $this->original(1000, 750);

        $this->assertSame([], ImageVariants::generate($original, null), 'a write with no owner must produce nothing');
        $this->assertDirectoryDoesNotExist($this->folder . '/variants');

        // and with an owner it writes, so "nothing" above is the guard rather than a broken fixture
        $this->assertSame([160, 320, 480, 768], array_keys(ImageVariants::generate($original, self::STORE)));

        // the READ side still answers without one: these files exist and must stay discoverable
        $this->assertSame([160, 320, 480, 768], array_keys(ImageVariants::existing($original, null)));
        $this->assertSame([160, 320, 480, 768], array_keys(ImageVariants::generate($original, null)), 'generate() with no owner still REPORTS what is there');
    }

    public function test_a_variant_directory_that_is_a_symlink_elsewhere_is_not_used(): void
    {
        /* The realpath comparison in variantDirectory() exists for this. A `variants` symlink is
         * the one way a path that passed every string rule still writes somewhere else. */
        $original = $this->original(1000, 750);
        $elsewhere = base_path('uploads/' . self::STORE . '-elsewhere');
        mkdir($elsewhere, 0o755, true);

        try {
            symlink($elsewhere, $this->folder . '/variants');

            $this->assertSame([], ImageVariants::generate($original, self::STORE));
            $this->assertSame([], glob($elsewhere . '/*') ?: [], 'a file was written through the symlink');
        } finally {
            @unlink($this->folder . '/variants');
            foreach ((array) glob($elsewhere . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($elsewhere);
        }
    }

    /** A 400x300 two-frame GIF, assembled by hand because gd writes only one frame. */
    private function twoFrameGif(): string
    {
        $frame = function (): string {
            return "\x21\xF9\x04\x00\x0A\x00\x00\x00"              // Graphic Control Extension
                . "\x2C\x00\x00\x00\x00\x90\x01\x2C\x01\x00"        // Image Descriptor, 400x300
                . "\x02\x02\x44\x01\x00";                           // 2-bit LZW data, one clear+EOI
        };

        return "GIF89a\x90\x01\x2C\x01\xF7\x00\x00"
            . str_repeat("\x00\x00\x00\xFF\xFF\xFF", 128)           // 256-entry global colour table
            . $frame() . $frame()
            . "\x3B";
    }

    /** The same JPEG with a minimal APP1/Exif segment carrying Orientation = 6. */
    private function withOrientationSix(string $jpeg): string
    {
        $ifd = "\x4D\x4D\x00\x2A\x00\x00\x00\x08"                   // big-endian TIFF header
            . "\x00\x01"                                            // one entry
            . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"    // Orientation (0x0112), SHORT, 1, value 6
            . "\x00\x00\x00\x00";                                   // no next IFD
        $payload = "Exif\x00\x00" . $ifd;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        // after the SOI marker, before everything else
        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }
}
