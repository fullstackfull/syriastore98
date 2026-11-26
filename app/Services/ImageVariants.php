<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * RESPONSIVE, MODERN-FORMAT COPIES OF A STORED UPLOAD -- WITH THE BUNDLED `gd` AND NOTHING ELSE.
 * =================================================================================================
 *
 * GAP-274, the pipeline half of the recovered Phase 03 bullet at
 * `master-plan/MASTER-PRODUCT-PLAN-v1.0-BASE.md:222` (SEO, indexing, performance, **الصور**,
 * structured data). The markup half -- `loading`, `decoding`, `fetchpriority` -- landed in
 * `71559a9ca`. This is the other half: a small set of narrower WebP copies of each storefront
 * product image, and the `srcset` that lets a browser pick one.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHAT IT MAY AND MAY NOT DO
 * ------------------------------------------------------------------------------------------------
 *
 *  - **The original is never touched.** Not overwritten, not deleted, not renamed, and its stored
 *    path is never changed -- a merchant's own content already references it. Variants are purely
 *    additive files, and the storefront's `<img src>` is still the original. A browser that cannot
 *    take WebP therefore gets exactly the bytes it gets today.
 *  - **No composer dependency.** No Intervention, no Imagine: `gd` only, which is what this host
 *    has (`imagewebp()` exists, `gd_info()['WebP Support']` is true).
 *  - **No AVIF.** `imageavif()` DOES NOT EXIST on this host -- measured, not assumed -- so AVIF is
 *    out of scope until the PHP build changes. That is a host rebuild, not an engineering choice.
 *  - **Local storage only.** `Utility::upload_file()` writes to the `theme` disk, whose root is
 *    `base_path('')`, when `storage_setting` is `local`; with `s3` or `wasabi` the bytes are not on
 *    this filesystem at all and `gd` cannot read them without a download. `StoredUpload::resolve()`
 *    refuses anything that does not resolve inside `base_path('uploads')`, so a remote-storage
 *    installation simply gets no variants and the storefront renders exactly as it does today.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE LADDER, AND WHERE THE NUMBERS COME FROM
 * ------------------------------------------------------------------------------------------------
 *
 * MEASURED IN CHROMIUM against the real storefront, all three themes, both directions, with the
 * runtime Tailwind JIT loaded (CLAUDE.md §6: a reading taken without it is a reading against a
 * cascade no shopper sees). Captured through the real routes, and for the product list through the
 * real `{slug}/product-filter` JSON that the page's own jQuery puts into `.product_filter`. The
 * box of each image, in CSS pixels, per viewport width:
 *
 *   element                         360   414   768  1024  1280  1536  1920
 *   product detail, main image      294   348   670   446   574   702   702
 *   product detail, thumbnail        87   105   155    99   131   163   163
 *   product card cover              328   382   358   355   288   352   352
 *
 * So the real demand is 87-702 CSS px, which on a 2x display is 174-1404 device px. The ladder is
 * **160, 320, 480, 768, 1280**: 160 serves the thumbnail at 1x, 320 the thumbnail at 2x and the
 * narrowest card, 480 every card at 1x and the main image on a phone, 768 the main image at 1024
 * and every card at 2x, 1280 the main image at 2x. Each rung is at most 2x the one below, so the
 * candidate a browser picks is never more than one rung wider than it needs. Five rungs rather
 * than six because the files are a storage cost and the sixth would have covered 1404 device px,
 * which 1280 already serves within 10%.
 *
 * A rung WIDER than the original is never written -- upscaling adds bytes and no detail -- so a
 * 600px original gets three variants and a 150px one gets none.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHERE A VARIANT LANDS, AND WHY THAT IS THE WHOLE SECURITY QUESTION
 * ------------------------------------------------------------------------------------------------
 *
 * Uploads are namespaced `uploads/<store id>/...`, and `uploads/uploads/<store id>/...` for the
 * product gallery, whose helper prepends the prefix a second time
 * (`Utility::keyWiseUpload_file()`). A variant must land inside the SAME store's folder; a name
 * that traversed out of it would be a new door into another tenant's files, or out of `uploads/`
 * altogether -- which is the shape of P1-SEC-18 and P1-SEC-28.
 *
 * Nothing here re-derives that rule. `relativePath()` is a PURE STRING function that:
 *
 *   1. puts the stored path through `StoredPathPolicy::isSafe()`, which already refuses `..`, `.`,
 *      an empty segment, a control byte, a backslash and anything outside the three roots uploads
 *      live under, and already peels the doubled `uploads/` before reading the tenant segment;
 *   2. requires the tenant segment to be a store folder in its canonical spelling -- a positive
 *      integer, so `uploads/0/`, `uploads/007/`, `uploads/profile/` and a loose file directly in
 *      `uploads/` all get no variant. `isSafe()` alone does NOT answer this when the owning store
 *      is unknown (it short-circuits on a null store id), which is why it is asked here;
 *   3. when the owning store IS known, requires the folder to be that store's. This is TIGHTER
 *      than `StoredPathPolicy`, which also admits another store of the same owner: a variant is
 *      written for a model whose `store_id` is known, so it never needs a sibling's folder, and
 *      refusing one keeps this function free of the database query that answer would cost;
 *   4. reduces the original's own file name to `[A-Za-z0-9_-]` and appends eight hex digits of
 *      `sha1(<stored path>)` and the rung. The reduction is what stops a client-chosen name from
 *      becoming a second path segment; the hash is what stops `photo.jpg` and `photo_jpg`, which
 *      reduce to the same string, from sharing one variant file -- the second would otherwise
 *      overwrite the first and the storefront would print one product's picture under another's.
 *      It is derived from the path rather than from random bytes so that the READER can recompute
 *      it without a lookup.
 *
 * `generate()` then adds the part a string cannot answer: the original goes through
 * `StoredUpload::resolve()`, which applies `realpath()` -- resolving `..` and symlinks the way the
 * operating system does, not lexically -- and refuses anything that does not come out inside
 * `base_path('uploads')`, is not a regular file, or names a platform default. The variant
 * directory is then built from the RESOLVED original's directory, and after `mkdir` its own
 * `realpath()` is required to be exactly that directory plus `/variants`. So a variant can only
 * ever be written into a `variants` folder beside an original that already passed every rule.
 *
 * `variants/` is a name no uploader writes: every writer puts the file directly in the directory it
 * was handed, and `UploadedFile::getClientOriginalName()` is `basename()`'d by Symfony, so a client
 * cannot introduce a path separator and cannot land a file inside a subfolder.
 *
 * ------------------------------------------------------------------------------------------------
 *  TWO WAYS A NAIVE RESIZE PRODUCES A VISIBLY WRONG PICTURE
 * ------------------------------------------------------------------------------------------------
 *
 *  - **EXIF ORIENTATION.** A browser rotates a JPEG according to its EXIF `Orientation` tag
 *    (`image-orientation: from-image` is the CSS default). `gd` does not read that tag, and
 *    `imagewebp()` writes no EXIF, so a phone photo saved as orientation 6 displays upright from
 *    the original and SIDEWAYS from an unrotated variant. Since the variant is the copy that
 *    paints, that would be a regression caused by this feature. The tag is read and applied, and
 *    when it swaps the axes the DISPLAYED width -- the one the no-upscale rule must compare
 *    against -- is the source's height.
 *  - **ANIMATION.** `gd` decodes one frame. A still WebP served in place of an animated GIF is a
 *    visible change to a merchant's content, so an animated source is skipped entirely and keeps
 *    its original only.
 */
final class ImageVariants
{
    /** The rungs, ascending. Derived from the browser measurement in this class's docblock. */
    public const WIDTHS = [160, 320, 480, 768, 1280];

    /** WebP quality. 82 is the knee of the size/quality curve for photographic content. */
    public const QUALITY = 82;

    /**
     * A source larger than this is left alone. `imagecreatefrom*` allocates 4 bytes per pixel, so
     * 40 megapixels is ~160MB of PHP memory for the source plus the target -- past that the resize
     * is likelier to exhaust `memory_limit` mid-request than to produce anything, and a save that
     * dies is worse than a product without variants.
     */
    public const MAX_SOURCE_PIXELS = 40000000;

    /** The raster types `gd` can decode here, and the function that does it. SVG and ICO are absent on purpose: an SVG is already resolution-independent (and carries script), and an icon is not a storefront photograph. */
    private const DECODERS = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_BMP => 'imagecreatefrombmp',
    ];

    /**
     * THE VARIANT'S PATH, relative to `base_path()`, or null when this original gets no variants.
     *
     * Pure: no filesystem, no database, no container. It is the one derivation of the name, shared
     * by the writer and the reader on purpose -- two derivations would drift and the reader would
     * advertise a `srcset` candidate nobody wrote.
     *
     * @param  int|null  $storeId  the store that owns the row, when the caller knows it
     */
    public static function relativePath(?string $storedPath, int $width, ?int $storeId = null): ?string
    {
        if (! in_array($width, self::WIDTHS, true)) {
            return null;
        }

        $storedPath = is_string($storedPath) ? trim($storedPath) : '';

        if ($storedPath === '' || ! StoredPathPolicy::isSafe($storedPath)) {
            return null;
        }

        // isSafe() also admits an absolute http(s) URL (a cover image kept on another host); there is
        // no local file behind one, so there is nothing to resize.
        if (preg_match('#^https?://#i', $storedPath) === 1) {
            return null;
        }

        $folder = self::storeFolder($storedPath);

        if ($folder === null || ($storeId !== null && $folder !== (int) $storeId)) {
            return null;
        }

        $name = basename($storedPath);
        $stem = preg_replace(['/[^A-Za-z0-9_-]+/', '/_{2,}/'], ['_', '_'], $name);
        $stem = trim((string) $stem, '_');
        $stem = substr($stem === '' ? 'image' : $stem, 0, 60);

        return dirname($storedPath) . '/variants/' . $stem . '-' . substr(sha1($storedPath), 0, 8) . '-' . $width . 'w.webp';
    }

    /**
     * Every rung's path for this original, whether or not the file exists. Pure.
     *
     * @return array<int, string> width => relative path
     */
    public static function candidates(?string $storedPath, ?int $storeId = null): array
    {
        $out = [];

        foreach (self::WIDTHS as $width) {
            $path = self::relativePath($storedPath, $width, $storeId);

            if ($path !== null) {
                $out[$width] = $path;
            }
        }

        return $out;
    }

    /**
     * The rungs that are actually on disk, in ascending width order.
     *
     * One `is_file()` per rung. PHP's stat cache makes a repeated question free within a request,
     * and a storefront page asks it at most five times per image.
     *
     * @return array<int, string> width => relative path
     */
    public static function existing(?string $storedPath, ?int $storeId = null): array
    {
        $out = [];

        foreach (self::candidates($storedPath, $storeId) as $width => $path) {
            if (is_file(base_path($path))) {
                $out[$width] = $path;
            }
        }

        return $out;
    }

    /**
     * WRITE THE MISSING RUNGS. Returns every rung that exists afterwards, ascending.
     *
     * It never throws: a product save must not fail because an image could not be resized. Every
     * refusal is logged with its reason, because "no variants" and "variants are broken" look the
     * same from the storefront.
     *
     * @return array<int, string> width => relative path
     */
    public static function generate(?string $storedPath, ?int $storeId = null): array
    {
        /*
         * A WRITE NEEDS A KNOWN OWNER. Reading does not.
         *
         * With a null store id `candidates()` asks only "is this A store folder", not "is it THIS
         * store's" -- `StoredPathPolicy::isSafe()` short-circuits on a null store id for the same
         * reason. That is fine for the read side, which only looks for files, but on the write side
         * it would mean a row whose owner cannot be determined could have variants written into
         * whichever store folder its path happens to name. Such a row is reachable: a `ProductImage`
         * whose own `store_id` is null and whose product's is too passes `StoredPathPolicy` with any
         * store's folder, because that rule is handed the same null. Nothing is read out and no file
         * is replaced -- the names are hash-derived and an existing variant is skipped -- but a few
         * kilobytes would land in a folder nobody proved belongs to the saver, and refusing is
         * free. A legacy row with no resolvable store therefore keeps its original only.
         */
        if ($storeId === null) {
            return self::existing($storedPath, null);
        }

        $candidates = self::candidates($storedPath, $storeId);

        if ($candidates === []) {
            return [];
        }

        $real = StoredUpload::resolve($storedPath);

        if ($real === null) {
            // StoredUpload::resolve() logs the cases that are interesting (outside uploads, another
            // tenant's folder, a platform default); a path whose file is simply not there is not.
            return [];
        }

        $info = @getimagesize($real);

        if ($info === false || ! isset(self::DECODERS[$info[2]])) {
            return [];
        }

        [$sourceWidth, $sourceHeight] = [(int) $info[0], (int) $info[1]];

        if ($sourceWidth < 1 || $sourceHeight < 1
            || $sourceWidth * $sourceHeight > self::MAX_SOURCE_PIXELS
            || ! self::fitsInMemory($sourceWidth * $sourceHeight)) {
            Log::info('an image variant was not generated: the source is outside the size this may decode', [
                'path' => $storedPath, 'width' => $sourceWidth, 'height' => $sourceHeight,
            ]);

            return [];
        }

        if (self::isAnimated($real, $info[2])) {
            Log::info('an image variant was not generated: the source is animated and gd decodes one frame', ['path' => $storedPath]);

            return [];
        }

        $orientation = self::orientation($real, $info[2]);
        // An orientation that swaps the axes makes the DISPLAYED width the source's height, and the
        // displayed width is what the no-upscale rule is about.
        $displayedWidth = in_array($orientation, [5, 6, 7, 8], true) ? $sourceHeight : $sourceWidth;

        $wanted = [];

        foreach ($candidates as $width => $path) {
            if ($width < $displayedWidth && ! is_file(base_path($path))) {
                $wanted[$width] = $path;
            }
        }

        if ($wanted !== []) {
            self::write($real, $storedPath, $wanted, $orientation, $info[2]);
        }

        return self::existing($storedPath, $storeId);
    }

    /**
     * WOULD DECODING THIS MANY PIXELS FIT IN WHAT IS LEFT OF `memory_limit`?
     *
     * `MAX_SOURCE_PIXELS` alone is not enough, and this is the one failure mode here that a
     * `try`/`catch` cannot contain: exceeding `memory_limit` is a FATAL error, not an exception, so
     * it would take the whole product save down rather than skipping the picture. The platform's
     * own upload ceiling does not bound the pixel count either -- the default is 2048 KB and a
     * heavily compressed JPEG of that size can be tens of megapixels.
     *
     * `gd` holds a truecolor image at 4 bytes per pixel. THREE copies are budgeted: the decoded
     * source, the rotated source when an EXIF tag calls for one (`imagerotate` allocates a second
     * image before freeing the first), and the largest target. An unlimited `memory_limit` (-1)
     * answers yes, because there is nothing to compare against.
     */
    private static function fitsInMemory(int $pixels): bool
    {
        $limit = self::memoryLimitInBytes();

        if ($limit === null) {
            return true;
        }

        // 90% of what is left: the rest of the request needs room too, and gd's own bookkeeping is
        // not counted in the 4-bytes-per-pixel figure.
        return $pixels * 4 * 3 < ($limit - memory_get_usage(true)) * 0.9;
    }

    /** `memory_limit` in bytes, or null when it is unlimited or unreadable. */
    private static function memoryLimitInBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value > 0 ? $value : null,
        };
    }

    /**
     * THE STORE FOLDER a stored path names, or null when it names none.
     *
     * The doubled `uploads/` of the product gallery is peeled first, exactly as
     * `StoredPathPolicy::inStoreFolder()` and `StoredUpload::resolve()` peel it. A folder that is
     * not a run of digits, or not the canonical spelling of its own number, is nobody's: `007` is
     * a folder no store owns rather than store 7's.
     */
    private static function storeFolder(string $storedPath): ?int
    {
        $segments = explode('/', $storedPath);

        // peel whichever of the three roots this is
        foreach ([['storage', 'uploads'], ['public', 'uploads'], ['uploads']] as $root) {
            if (array_slice($segments, 0, count($root)) === $root) {
                $segments = array_slice($segments, count($root));
                break;
            }
        }

        while (($segments[0] ?? null) === 'uploads' && count($segments) > 1) {
            array_shift($segments);
        }

        $folder = $segments[0] ?? '';

        if (! ctype_digit($folder) || $folder !== (string) (int) $folder || (int) $folder <= 0 || count($segments) < 2) {
            return null;
        }

        return (int) $folder;
    }

    /**
     * Decode once, resize to each wanted rung, write each as WebP.
     *
     * The write goes to a temporary name in the same directory and is then `rename`d into place,
     * so a reader never sees a half-written file: the reader's test is `is_file()`, and `rename()`
     * within one filesystem is atomic.
     *
     * @param  array<int, string>  $wanted  width => relative path
     */
    private static function write(string $real, string $storedPath, array $wanted, int $orientation, int $type): void
    {
        $directory = self::variantDirectory($real);

        if ($directory === null) {
            return;
        }

        $source = null;

        try {
            $decoder = self::DECODERS[$type];
            $source = @$decoder($real);

            if ($source === false || $source === null) {
                Log::info('an image variant was not generated: gd could not decode the source', ['path' => $storedPath]);

                return;
            }

            $source = self::applyOrientation($source, $orientation);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            foreach ($wanted as $width => $relative) {
                $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
                $target = imagecreatetruecolor($width, $height);

                // Without these an alpha channel is composited onto black: a PNG logo with a
                // transparent background would come out in a black box.
                imagealphablending($target, false);
                imagesavealpha($target, true);

                imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

                $temporary = $directory . '/.' . bin2hex(random_bytes(6)) . '.webp.part';

                /*
                 * THE FINAL NAME IS BUILT FROM `$directory`, THE PATH WHOSE `realpath()` WAS
                 * VERIFIED, rather than from `base_path($relative)`. The two resolve to the same
                 * file today -- `$relative`'s directory is `$real`'s by construction -- but only
                 * `$directory` has been through the comparison that refuses a `variants` symlink
                 * pointing elsewhere, and a write should not depend on two paths being equivalent.
                 */
                if (@imagewebp($target, $temporary, self::QUALITY) && is_file($temporary)) {
                    @rename($temporary, $directory . DIRECTORY_SEPARATOR . basename($relative));
                } else {
                    @unlink($temporary);
                    Log::info('an image variant was not written', ['path' => $storedPath, 'width' => $width]);
                }

                imagedestroy($target);
            }
        } catch (\Throwable $e) {
            // A save must not fail because a picture could not be resized.
            Log::warning('an image variant could not be generated', ['path' => $storedPath, 'error' => $e->getMessage()]);
        } finally {
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
        }
    }

    /**
     * The `variants` folder beside a RESOLVED original, created if need be, or null.
     *
     * The realpath comparison after `mkdir` is the confinement: whatever `$real`'s directory turns
     * out to be, the variant directory has to be exactly that plus one `variants` segment. A
     * symlink planted at `variants` pointing elsewhere is refused by the same comparison that
     * admits the real thing.
     */
    private static function variantDirectory(string $real): ?string
    {
        $parent = realpath(dirname($real));

        if ($parent === false) {
            return null;
        }

        $directory = $parent . DIRECTORY_SEPARATOR . 'variants';

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            Log::info('an image variant directory could not be created', ['directory' => $directory]);

            return null;
        }

        $resolved = realpath($directory);

        if ($resolved === false || $resolved !== $directory) {
            Log::warning('an image variant directory does not resolve to the folder beside its original and was not used', [
                'directory' => $directory, 'resolved' => $resolved === false ? null : $resolved,
            ]);

            return null;
        }

        return $resolved;
    }

    /** The EXIF `Orientation` of a JPEG, or 1 when there is none to read. */
    private static function orientation(string $real, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return 1;
        }

        try {
            $exif = @exif_read_data($real);
        } catch (\Throwable $e) {
            return 1;
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** Rotate and flip a decoded image the way a browser would, from its EXIF orientation. */
    private static function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if ($orientation === 2 || $orientation === 4 || $orientation === 5 || $orientation === 7) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        // imagerotate turns COUNTER-clockwise, and these tags describe the rotation the viewer must
        // apply clockwise, so the angles are the complements.
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    /**
     * Is this source animated? `gd` decodes one frame, and a still copy of an animated picture is a
     * visible change to a merchant's content rather than an optimisation of it.
     *
     * GIF: a Graphic Control Extension block (`21 F9 04`) per frame, so more than one means more
     * than one frame. WebP: the `VP8X` header's ANIMATION bit, and the `ANIM`/`ANMF` chunks that go
     * with it. Only the head of the file is read.
     */
    private static function isAnimated(string $real, int $type): bool
    {
        if ($type !== IMAGETYPE_GIF && $type !== IMAGETYPE_WEBP) {
            return false;
        }

        $handle = @fopen($real, 'rb');

        if ($handle === false) {
            // Unreadable here means unreadable by the decoder too; say "animated" so nothing is written.
            return true;
        }

        $head = (string) fread($handle, 262144);
        fclose($handle);

        if ($type === IMAGETYPE_WEBP) {
            return str_contains($head, 'ANIM') || str_contains($head, 'ANMF');
        }

        return substr_count($head, "\x21\xF9\x04") > 1;
    }
}
