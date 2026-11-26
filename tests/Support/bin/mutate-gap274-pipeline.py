#!/usr/bin/env python3
"""
GAP-274 PIPELINE HALF -- the mutation group for the variant generator and its delivery.

Every mutation is a change that SHOULD break a named test. A survivor is a hole in the test, not a
licence to weaken the mutation (CLAUDE.md §3 / the skill's rule 9: when a mutation survives, ask
what input would make the two behaviours disagree).

Run from the repository root:  python3 tests/Support/bin/mutate-gap274-pipeline.py
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

SERVICE = 'app/Services/ImageVariants.php'
HELPER = 'app/Helper/helper.php'
OBSERVER = 'app/Observers/ProductImageVariantObserver.php'
PROVIDER = 'app/Providers/AppServiceProvider.php'
DETAIL = 'resources/views/front_end/pages/product-detail.blade.php'
CARD = 'themes/greentic/views/components/product-card.blade.php'

PATHS = 'tests/Unit/Media/ImageVariantPathTest.php'
GEN = 'tests/Unit/Media/ImageVariantGenerationTest.php'
RENDER = 'tests/Feature/Storefront/StorefrontResponsiveImageRendersTest.php'
CENSUS = 'tests/Unit/Ui/StorefrontPictureSourceTest.php'
LOADING = 'tests/Unit/Ui/StorefrontImageDeliveryTest.php'

MUTATIONS = [
    # ---------------------------------------------------------------- the path rule
    Mutation(
        'the stored-path rule is not consulted',
        SERVICE,
        "if ($storedPath === '' || ! StoredPathPolicy::isSafe($storedPath)) {",
        "if ($storedPath === '') {",
        PATHS, 'test_a_path_that_is_not_a_confined_upload_gets_no_variant',
    ),
    Mutation(
        'a path with no store folder is accepted',
        SERVICE,
        "if ($folder === null || ($storeId !== null && $folder !== (int) $storeId)) {",
        "if (false) {",
        PATHS, 'test_a_path_that_is_not_a_confined_upload_gets_no_variant',
    ),
    Mutation(
        "another store's folder is accepted",
        SERVICE,
        "($storeId !== null && $folder !== (int) $storeId)",
        "($storeId !== null && false)",
        PATHS, 'test_the_store_folder_is_enforced_when_the_owning_store_is_known',
    ),
    Mutation(
        'the hash that separates colliding names is dropped',
        SERVICE,
        "return dirname($storedPath) . '/variants/' . $stem . '-' . substr(sha1($storedPath), 0, 8) . '-' . $width . 'w.webp';",
        "return dirname($storedPath) . '/variants/' . $stem . '-' . $width . 'w.webp';",
        PATHS, 'test_two_originals_whose_reduced_names_collide_still_get_different_variants',
    ),
    Mutation(
        "the original's name is not reduced",
        SERVICE,
        "$stem = preg_replace(['/[^A-Za-z0-9_-]+/', '/_{2,}/'], ['_', '_'], $name);",
        "$stem = $name;",
        PATHS, 'test_a_variant_name_carries_nothing_a_path_could_be_built_from',
    ),
    Mutation(
        'an off-ladder width is honoured',
        SERVICE,
        "if (! in_array($width, self::WIDTHS, true)) {\n            return null;\n        }",
        "if (false) {\n            return null;\n        }",
        PATHS, 'test_a_width_that_is_not_on_the_ladder_gets_no_path',
    ),

    # ---------------------------------------------------------------- generation
    Mutation(
        'a write with no known owner is allowed',
        SERVICE,
        "if ($storeId === null) {\n            return self::existing($storedPath, null);\n        }",
        "if (false) {\n            return self::existing($storedPath, null);\n        }",
        GEN, 'test_nothing_is_written_when_the_owning_store_cannot_be_determined',
    ),
    Mutation(
        'a rung wider than the original is written (upscaling)',
        SERVICE,
        "if ($width < $displayedWidth && ! is_file(base_path($path))) {",
        "if (! is_file(base_path($path))) {",
        GEN, 'test_an_original_narrower_than_the_smallest_rung_gets_nothing',
    ),
    Mutation(
        'an existing variant is rewritten every time',
        SERVICE,
        "if ($width < $displayedWidth && ! is_file(base_path($path))) {",
        "if ($width < $displayedWidth) {",
        GEN, 'test_generation_is_idempotent_and_does_not_rewrite_what_is_there',
    ),
    Mutation(
        'an animated source is flattened into a still variant',
        SERVICE,
        "if (self::isAnimated($real, $info[2])) {",
        "if (false) {",
        GEN, 'test_an_animated_gif_keeps_its_original_only',
    ),
    Mutation(
        'the EXIF orientation is ignored',
        SERVICE,
        "$orientation = self::orientation($real, $info[2]);",
        "$orientation = 1;",
        GEN, 'test_a_rotating_exif_tag_is_applied_so_the_variant_is_not_sideways',
    ),
    Mutation(
        'a `variants` symlink pointing elsewhere is used',
        SERVICE,
        "if ($resolved === false || $resolved !== $directory) {",
        "if (false) {",
        GEN, 'test_a_variant_directory_that_is_a_symlink_elsewhere_is_not_used',
    ),
    Mutation(
        'the memory budget is removed, so a large source can fatal the save',
        SERVICE,
        "|| ! self::fitsInMemory($sourceWidth * $sourceHeight)) {",
        "|| false) {",
        GEN, 'test_a_source_that_would_not_fit_in_memory_is_skipped_rather_than_fatal',
    ),
    Mutation(
        'the decoded type is not checked, so anything is attempted',
        SERVICE,
        "if ($info === false || ! isset(self::DECODERS[$info[2]])) {",
        "if ($info === false) {",
        GEN, 'test_an_image_type_gd_cannot_decode_is_refused_before_anything_is_created',
    ),
    Mutation(
        'the variant is written as PNG rather than WebP',
        SERVICE,
        "if (@imagewebp($target, $temporary, self::QUALITY) && is_file($temporary)) {",
        "if (@imagepng($target, $temporary) && is_file($temporary)) {",
        GEN, 'test_every_rung_below_the_original_is_written_and_none_above_it',
    ),
    Mutation(
        'the aspect ratio is not kept',
        SERVICE,
        "$height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));",
        "$height = $sourceHeight;",
        GEN, 'test_every_rung_below_the_original_is_written_and_none_above_it',
    ),
    Mutation(
        'existing() reports candidates it has not looked for',
        SERVICE,
        "if (is_file(base_path($path))) {\n                $out[$width] = $path;\n            }",
        "$out[$width] = $path;",
        GEN, 'test_existing_reports_only_what_is_on_disk',
    ),
    Mutation(
        'the original is overwritten by its own variant',
        SERVICE,
        "@rename($temporary, $directory . DIRECTORY_SEPARATOR . basename($relative));",
        "@rename($temporary, $real);",
        GEN, 'test_the_original_is_not_touched',
    ),

    # ---------------------------------------------------------------- the generation POINT
    Mutation(
        'the Product observer is not registered',
        PROVIDER,
        "Product::observe(ProductImageVariantObserver::class);",
        "// Product::observe(ProductImageVariantObserver::class);",
        RENDER, 'test_the_variants_are_generated_at_save_and_sit_in_the_stores_own_folder',
    ),
    Mutation(
        'the ProductImage observer is not registered',
        PROVIDER,
        "ProductImage::observe(ProductImageVariantObserver::class);",
        "// ProductImage::observe(ProductImageVariantObserver::class);",
        RENDER, 'test_the_variants_are_generated_at_save_and_sit_in_the_stores_own_folder',
    ),
    Mutation(
        'an insert no longer generates (the created hook is inert)',
        OBSERVER,
        "    public function created(Product|ProductImage $model): void\n    {\n        $this->generateFor($model);\n    }",
        "    public function created(Product|ProductImage $model): void\n    {\n    }",
        RENDER, 'test_the_variants_are_generated_at_save_and_sit_in_the_stores_own_folder',
    ),
    Mutation(
        'every save regenerates, checkout included',
        OBSERVER,
        "if ($model->wasChanged($column)) {",
        "if (true) {",
        RENDER, 'test_a_save_that_does_not_change_the_image_generates_nothing',
    ),
    Mutation(
        'an update no longer generates (the saved hook is inert)',
        OBSERVER,
        "if ($model->wasChanged($column)) {",
        "if (false) {",
        RENDER, 'test_a_save_that_does_not_change_the_image_generates_nothing',
    ),
    Mutation(
        "the row's store is not passed, so the confinement has nothing to check",
        OBSERVER,
        "ImageVariants::generate($model->cover_image_path, self::storeOf($model->store_id));",
        "ImageVariants::generate($model->cover_image_path, null);",
        RENDER, 'test_a_row_whose_path_names_a_sibling_stores_folder_gets_no_variants_there',
    ),

    # ---------------------------------------------------------------- delivery
    # `if ($variants === []) return ''` ALONE IS AN EQUIVALENT MUTANT and is not listed: with it
    # removed the loop below it iterates nothing, `$candidates` stays empty and the SECOND guard
    # returns the same empty string. The two are not independent -- the first is a fast path -- so
    # the mutation that decides the behaviour is the second one, below. Reported as equivalent
    # rather than as a survivor, because weakening a test to "kill" it would be a lie and deleting
    # a guard whose removal changes nothing would be churn.
    # NEITHER EMPTINESS GUARD CAN BE KILLED ON ITS OWN, and that is a property of the code rather
    # than a hole in the test: each is reached only when the other did not fire. `$variants === []`
    # is the one the "no variants" input hits, and with it removed the loop iterates nothing and the
    # second returns the same empty string; the second is only reachable when `get_file()` answers
    # empty for EVERY candidate, which cannot be produced from outside the function. Both are listed
    # as one mutation, which is the question a test can actually answer: is the empty answer pinned?
    Mutation(
        'neither emptiness guard remains, so a <source> is emitted with nothing behind it',
        HELPER,
        "if ($variants === []) {\n            return '';\n        }",
        "if (false) {\n            return '';\n        }\n\n        if (true) {\n            return '<source type=\"image/webp\" srcset=\"\" sizes=\"\">';\n        }",
        RENDER, 'test_an_image_with_no_variants_renders_with_no_source_at_all',
    ),
    Mutation(
        'the URL is not percent-encoded, so a candidate splits in two',
        HELPER,
        "$url = (string) get_file(implode('/', array_map('rawurlencode', explode('/', $relative))));",
        "$url = (string) get_file($relative);",
        RENDER, 'test_a_variant_url_is_encoded_rather_than_interpolated',
    ),
    Mutation(
        'the sizes value is interpolated unescaped',
        HELPER,
        "'\" sizes=\"' . e($sizes) . '\">'",
        "'\" sizes=\"' . $sizes . '\">'",
        RENDER, 'test_a_sizes_value_is_escaped_before_it_reaches_the_attribute',
    ),
    Mutation(
        "the caller's store is discarded, so another store's files are offered",
        HELPER,
        "is_numeric($storeId) && (int) $storeId > 0 ? (int) $storeId : null",
        "null",
        RENDER, 'test_another_stores_variants_are_not_offered',
    ),
    Mutation(
        'the width descriptor is dropped from every candidate',
        HELPER,
        "$candidates[] = e($url) . ' ' . $width . 'w';",
        "$candidates[] = e($url);",
        RENDER, 'test_every_gallery_picture_offers_webp_at_every_generated_width',
    ),

    # ---------------------------------------------------------------- the markup
    Mutation(
        "the main image's <source> is removed from the gallery slide",
        DETAIL,
        "{!! storefront_webp_source($item->image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_MAIN, $product->store_id) !!}",
        "",
        CENSUS, 'test_every_source_is_built_from_the_same_image_its_img_falls_back_to',
    ),
    Mutation(
        "the main image's <source> is built from a different image",
        DETAIL,
        "{!! storefront_webp_source($item->image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_MAIN, $product->store_id) !!}",
        "{!! storefront_webp_source($product->cover_image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_MAIN, $product->store_id) !!}",
        CENSUS, 'test_every_source_is_built_from_the_same_image_its_img_falls_back_to',
    ),
    Mutation(
        "the main image declares the thumbnail's sizes",
        DETAIL,
        "storefront_webp_source($item->image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_MAIN, $product->store_id)",
        "storefront_webp_source($item->image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_THUMBNAIL, $product->store_id)",
        RENDER, 'test_the_main_image_and_the_thumbnail_declare_their_own_measured_sizes',
    ),
    Mutation(
        'the <img> fallback is removed from inside the <picture>',
        DETAIL,
        """                                        <img src="{{ storefront_image($product->cover_image_path) }}" alt="{{ $product->name }}"
                                            loading="eager" fetchpriority="high"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />""",
        "",
        CENSUS, 'test_no_storefront_picture_is_without_the_image_it_falls_back_to',
    ),
    Mutation(
        'the gallery slide loses its loading policy',
        DETAIL,
        '@if ($loop->first) loading="eager" fetchpriority="high" @else loading="lazy" decoding="async" @endif',
        '@if ($loop->first) fetchpriority="high" @endif',
        LOADING, 'test_every_storefront_image_declares_when_it_loads',
    ),
    Mutation(
        "a theme's product card loses its wrap",
        CARD,
        "{!! storefront_webp_source($product->cover_image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_CARD, $product->store_id) !!}",
        "",
        CENSUS, 'test_every_source_is_built_from_the_same_image_its_img_falls_back_to',
    ),
    Mutation(
        "a theme's product card loses its <picture> element entirely",
        CARD,
        "                <picture>\n",
        "",
        CENSUS, 'test_the_wrapped_images_are_the_declared_ones',
    ),
    Mutation(
        "a theme's product card declares a literal sizes instead of the measured constant",
        CARD,
        "storefront_webp_source($product->cover_image_path, \\App\\Support\\Storefront\\StorefrontImageSizes::PRODUCT_CARD, $product->store_id)",
        "storefront_webp_source($product->cover_image_path, '100vw', $product->store_id)",
        CENSUS, 'test_every_sizes_argument_is_a_measured_constant',
    ),
    Mutation(
        "a theme's product card loses the loading policy the widening gave it",
        CARD,
        'loading="lazy" decoding="async"\n                    class="w-full h-full object-contain group-hover:scale-105 transition duration-500">',
        'class="w-full h-full object-contain group-hover:scale-105 transition duration-500">',
        LOADING, 'test_every_storefront_image_declares_when_it_loads',
    ),
    Mutation(
        "a theme's product card gets its demo alt back",
        CARD,
        'alt="{{ $product->name }}"\n                    loading="lazy" decoding="async"',
        'alt="Fresh Organic Onion"\n                    loading="lazy" decoding="async"',
        LOADING, 'test_no_storefront_image_claims_a_product_or_person_the_data_does_not_name',
    ),
    Mutation(
        'the census stops reading the theme views it was widened to cover',
        LOADING,
        "        'themes/techzonix/views',\n        'themes/greentic/views',\n        'themes/stylique/views',\n        'resources/views/front_end',\n    ];\n\n    /**\n     * Storefront views that are NOT under any of those roots",
        "        'themes/techzonix/views/front_end',\n        'themes/greentic/views/front_end',\n        'themes/stylique/views/front_end',\n        'resources/views/front_end',\n    ];\n\n    /**\n     * Storefront views that are NOT under any of those roots",
        LOADING, 'test_the_census_really_reads_the_storefront_image_tags',
    ),
]

if __name__ == '__main__':
    sys.exit(run(MUTATIONS))
