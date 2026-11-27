<?php

namespace Tests\Unit\Media;

use App\Services\ImageVariants;
use Tests\TestCase;

/**
 * WHERE A VARIANT MAY BE WRITTEN, AND WHERE IT MAY NOT.
 * =================================================================================================
 *
 * GAP-274, pipeline half. `ImageVariants::relativePath()` is the ONE function that turns a stored
 * original into a variant path, and it is shared by the writer and by the reader on purpose: if
 * they derived the name separately they would drift, and the reader would advertise a `srcset`
 * candidate nobody wrote.
 *
 * IT IS ALSO THE PLACE A TRAVERSAL WOULD BE A NEW DOOR. The task that opened this row says so, and
 * the uploads tree is exactly the shape that makes it easy: uploads are namespaced
 * `uploads/<store id>/...` -- and `uploads/uploads/<store id>/...` for the product gallery, whose
 * helper prepends the prefix twice (`Utility::keyWiseUpload_file()`). So a variant has to land in
 * the SAME store's folder, and a stored path that names another tenant's folder, or no store's
 * folder, must get no variant at all rather than a variant somewhere convenient.
 *
 * The confinement is NOT re-derived here. `App\Services\StoredPathPolicy::isSafe()` already
 * encodes it -- the three roots uploads really live under, the refusal of `..`, `.`, empty
 * segments, control bytes and backslashes, and the store-folder rule with the doubled prefix
 * peeled -- and `App\Services\StoredUpload::resolve()` already resolves `..` and symlinks with
 * `realpath()` before anything is touched. This class calls both.
 */
class ImageVariantPathTest extends TestCase
{
    public function test_the_ladder_is_the_measured_one_and_is_ascending(): void
    {
        $this->assertSame([160, 320, 480, 768, 1280], ImageVariants::WIDTHS);

        $sorted = ImageVariants::WIDTHS;
        sort($sorted);

        $this->assertSame($sorted, ImageVariants::WIDTHS, 'the ladder is read in order by srcset');
        $this->assertSame($sorted, array_values(array_unique($sorted)), 'a repeated rung would be a repeated srcset candidate');
    }

    public function test_a_variant_sits_in_a_variants_folder_beside_its_original(): void
    {
        $this->assertSame(
            'uploads/7/variants/37_1764000000_ab12cd34_jpg-' . substr(sha1('uploads/7/37_1764000000_ab12cd34.jpg'), 0, 8) . '-320w.webp',
            ImageVariants::relativePath('uploads/7/37_1764000000_ab12cd34.jpg', 320)
        );
    }

    public function test_a_gallery_path_with_the_doubled_prefix_keeps_both_segments(): void
    {
        $path = ImageVariants::relativePath('uploads/uploads/7/photo.png', 480);

        $this->assertNotNull($path);
        $this->assertStringStartsWith('uploads/uploads/7/variants/', $path);
        $this->assertStringEndsWith('-480w.webp', $path);
    }

    public function test_the_store_folder_is_enforced_when_the_owning_store_is_known(): void
    {
        $this->assertNotNull(
            ImageVariants::relativePath('uploads/7/photo.png', 320, 7),
            'premise: store 7\'s own folder is allowed, or the negative below proves nothing'
        );

        $this->assertNull(
            ImageVariants::relativePath('uploads/9/photo.png', 320, 7),
            'store 7 must not be able to write a variant into store 9\'s folder'
        );
    }

    /**
     * @dataProvider refusedPaths
     */
    public function test_a_path_that_is_not_a_confined_upload_gets_no_variant(string $path, string $why): void
    {
        $this->assertNull(ImageVariants::relativePath($path, 320), $why);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedPaths(): array
    {
        return [
            'traversal out of the store folder' => ['uploads/7/../9/photo.png', 'a `..` segment leaves the store folder'],
            'traversal out of uploads' => ['uploads/../.env', 'a `..` segment leaves the uploads root'],
            'single dot segment' => ['uploads/7/./photo.png', 'a `.` segment is not a stored upload path'],
            'absolute path' => ['/etc/passwd', 'an absolute path has no uploads root as its first segment'],
            'outside uploads' => ['public/index.php', 'nothing outside the uploads roots is an upload'],
            'themes directory' => ['themes/stylique/assets/img/x.png', 'a theme asset is not a merchant upload'],
            'backslash' => ['uploads\\7\\photo.png', 'a backslash is refused by the stored-path rule'],
            'nul byte' => ["uploads/7/photo\0.png", 'a control byte is refused by the stored-path rule'],
            'no store folder' => ['uploads/profile/avatar.png', 'a folder that is not a run of digits encodes no owner'],
            'bare file in uploads' => ['uploads/photo.png', 'a loose file directly in uploads/ has no store folder'],
            'empty' => ['', 'there is no original'],
            'remote url' => ['https://example.test/photo.png', 'a remote image is not on this disk'],
            'store folder zero' => ['uploads/0/photo.png', 'folder `0` belongs to no store'],
            'store folder padded' => ['uploads/007/photo.png', 'a store\'s folder is its id in its canonical spelling'],
        ];
    }

    public function test_a_width_that_is_not_on_the_ladder_gets_no_path(): void
    {
        $this->assertNull(
            ImageVariants::relativePath('uploads/7/photo.png', 321),
            'an off-ladder width would be a file the reader never looks for'
        );
    }

    public function test_two_originals_whose_reduced_names_collide_still_get_different_variants(): void
    {
        /* `photo.jpg` and `photo_jpg` both reduce to `photo_jpg`, so the reduction ALONE would put
         * two different originals' variants in one file -- the second would overwrite the first and
         * the storefront would print one product's picture under another's name. The eight hex
         * digits of sha1(original path) are what separate them, and they are derived from the
         * stored path rather than from random bytes so the reader can recompute them. */
        $a = ImageVariants::relativePath('uploads/7/photo.jpg', 320);
        $b = ImageVariants::relativePath('uploads/7/photo_jpg', 320);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a, $b);
    }

    public function test_a_variant_name_carries_nothing_a_path_could_be_built_from(): void
    {
        /* The original's own name reaches the variant's, so the reduction is the thing that keeps a
         * client-chosen name from becoming a second path segment. `Utility::upload_file()` stores
         * `rand . '_' . time() . '_' . $file->getClientOriginalName()`, and that last part is the
         * client's. Symfony's getClientOriginalName() is basename()'d, so a separator cannot arrive
         * that way today -- this asserts the property rather than trusting that. */
        $path = ImageVariants::relativePath('uploads/7/a b;c&d$e%f(g).jpg', 160);

        $this->assertNotNull($path);

        $name = basename($path);

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+-[0-9a-f]{8}-160w\.webp\z/', $name, 'the variant name is letters, digits, `_` and `-`, a hash and the rung');
        $this->assertSame('uploads/7/variants/' . $name, $path);
    }

    public function test_the_reader_and_the_writer_derive_the_same_name(): void
    {
        /* The whole reason relativePath() is public: ImageVariants::existing() must look for
         * exactly what generate() writes. A test that only drove one of them would pass while the
         * two drifted, and the symptom would be a srcset candidate that 404s. */
        $original = 'uploads/7/photo.png';

        foreach (ImageVariants::WIDTHS as $w) {
            $this->assertSame(
                ImageVariants::relativePath($original, $w),
                ImageVariants::candidates($original)[$w] ?? null,
                "the candidate list and the path function disagree at {$w}w"
            );
        }
    }
}
