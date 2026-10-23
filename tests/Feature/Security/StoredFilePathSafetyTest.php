<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Services\StoredPathPolicy;
use App\Services\StoredUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P1-SEC-28 -- unlinks of a path READ FROM THE DATABASE that did not go through StoredUpload.
 * =============================================================================================
 *
 * Three layers, each measured here:
 *   1. every HOST unlink of a stored path goes through App\Services\StoredUpload (a literal path, inside uploads/, in the owner's folder) --
 *      a census pins the list, and the web product-delete route is driven with a poisoned row;
 *   2. a WRITE-side rule (App\Services\StoredPathPolicy, on Product, ProductImage and Setting) keeps the values the VENDORED deleters read
 *      (AuctionProduct, DigitalProduct, SignInWithLinkedIn, SignInWithFacebook -- not editable) inside the roots uploads live in. The
 *      ImportExport CSV importer writes those columns from a spreadsheet cell;
 *   3. what stays outside uploads/ is never removed by the host: the shared default avatar, ImportExport's bundled default image.
 */
class StoredFilePathSafetyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->scratch = [];

        parent::tearDown();
    }

    private function decoy(string $name = 'p1sec28-decoy.txt'): string
    {
        $path = base_path($name);
        file_put_contents($path, 'DO NOT DELETE');
        $this->scratch[] = $path;

        return $path;
    }

    private function productIn($store, array $extra = []): Product
    {
        return Product::forceCreate(array_merge([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(), 'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 9, 'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ], $extra));
    }

    // ---------------------------------------------------------------------------------------------
    // the policy
    // ---------------------------------------------------------------------------------------------

    public static function safePaths(): array
    {
        return array_map(fn ($p) => [$p], [null, '', 'uploads/12/cover.png', 'uploads/uploads/12/gallery/a b.png', 'public/uploads/x.jpg', 'storage/uploads/x.jpg',
            'uploads/logo/logo-dark.png', StoredPathPolicy::IMPORT_EXPORT_DEFAULT,
            // characters real upload names contain; File::delete is unlink(), nothing here globs (REVIEW: the first version refused these AFTER
            // the file was written and the quota debited)
            'uploads/12/photo[1].jpg', 'uploads/12/what?.png', 'uploads/12/{a}.png', 'uploads/12/a|b.png', 'uploads/12/a*b.png', 'uploads/12/a"b.png',
            'uploads/12/a<b>.png', 'uploads/12/صورة.png',
            // an absolute URL names no file (see testAnAbsoluteUrlCannotReachAFileThroughBasePath)
            'https://cdn.example.com/p/a.png', 'http://cdn.example.com/a.png?v=1', 'HTTPS://CDN.EXAMPLE.COM/A.PNG']);
    }

    /** @dataProvider safePaths */
    public function test_a_path_inside_the_upload_roots_is_accepted(?string $path): void
    {
        $this->assertTrue(StoredPathPolicy::isSafe($path), (string) $path);
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($p) => [$p], ['.env', 'config/app.php', 'app/Models/Utility.php', 'database/database.sqlite', 'vendor/autoload.php', 'composer.json',
            'avatar.png', '/etc/passwd', '/uploads/x.png', 'C:\\Windows\\win.ini', 'c:/x', '..', '../x', 'uploads/../.env', 'uploads/../../etc/passwd', 'uploads/./x',
            'uploads//x', 'uploads/', 'uploads', "uploads/x\0.png", "uploads/x\n.png",
            'uploads\\..\\.env', 'file:///etc/passwd', 'php://filter/x', 'phar://uploads/x.phar', 'data://text/plain;base64,AAAA', 'ftp://x/y', 'Uploads/x.png', ' uploads/x.png',
            'packages/workdo/ImportExport/src/Other.php',
            // REVIEW FINDING: these were accepted because `public/`, `storage/` and `themes/` were whole roots, so a vendored deleter could
            // still be pointed at the front controller, the log or a session file
            'public/index.php', 'public/.htaccess', 'public/build/manifest.json', 'public/assets/css/app.css', 'storage/logs/laravel.log',
            'storage/framework/sessions/abc', 'storage/app/.env', 'storage/app/public/uploads/x.png', 'themes/greentic/assets/css/main-style.css',
            'public/uploads', 'storage/uploads', 'uploads-evil/x.png', 'public/uploads-evil/x.png', 'public/../.env', 'storage/uploads/../../.env']);
    }

    /** @dataProvider unsafePaths */
    public function test_a_path_outside_the_upload_roots_or_with_traversal_is_refused(string $path): void
    {
        $this->assertFalse(StoredPathPolicy::isSafe($path), json_encode($path));
    }

    /**
     * Why an absolute URL is safe to store: a vendored deleter does File::delete(base_path($value)) = unlink("<root>/https://x/../../victim"),
     * and the kernel resolves `https:` as a directory component first. Executed against a real directory tree, not argued.
     */
    public function test_an_absolute_url_cannot_reach_a_file_through_base_path(): void
    {
        $root = sys_get_temp_dir() . '/p1sec28-url-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/victim', 'DO NOT DELETE');

        try {
            foreach (['https://x/../../victim', 'https://x/../victim', 'http://x/../../../victim'] as $value) {
                $this->assertTrue(StoredPathPolicy::isSafe($value), $value);
                @unlink($root . DIRECTORY_SEPARATOR . ltrim($value, DIRECTORY_SEPARATOR));
                $this->assertFileExists($root . '/victim', "unlink of <root>/{$value} reached a file");
            }
        } finally {
            @unlink($root . '/victim');
            @rmdir($root);
        }
    }

    // -- the owning store -------------------------------------------------------------------------------------

    public function test_with_a_known_store_only_that_stores_folder_or_one_its_owner_runs_is_accepted(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $second = $this->makeStore($ownerA);

        $ids = ['own' => $storeA->id, 'sibling' => $second->id, 'other' => $storeB->id];

        $this->assertTrue(StoredPathPolicy::isSafe("uploads/{$ids['own']}/p.png", $storeA->id));
        $this->assertTrue(StoredPathPolicy::isSafe("uploads/uploads/{$ids['own']}/g/p.png", $storeA->id), 'the gallery helper doubles the prefix');
        $this->assertTrue(StoredPathPolicy::isSafe("public/uploads/{$ids['own']}/p.png", $storeA->id));
        $this->assertTrue(StoredPathPolicy::isSafe("uploads/{$ids['sibling']}/p.png", $storeA->id), 'a store the same owner runs');
        $this->assertTrue(StoredPathPolicy::isSafe(StoredPathPolicy::IMPORT_EXPORT_DEFAULT, $storeA->id));
        $this->assertTrue(StoredPathPolicy::isSafe('https://cdn.example.com/a.png', $storeA->id));

        $this->assertFalse(StoredPathPolicy::isSafe("uploads/{$ids['other']}/p.png", $storeA->id), "another tenant's folder");
        $this->assertFalse(StoredPathPolicy::isSafe("uploads/uploads/{$ids['other']}/g/p.png", $storeA->id), "another tenant's gallery folder");
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/profile/avatar.png', $storeA->id), 'a shared platform folder');
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/logo/logo-dark.png', $storeA->id));
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/avatar.png', $storeA->id), 'a file directly in uploads/ has no owner');
        $this->assertFalse(StoredPathPolicy::isSafe("uploads/{$ids['own']}", $storeA->id), 'a folder, not a file');
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/999999/p.png', $storeA->id), 'a folder of a store that does not exist');
        $this->assertFalse(StoredPathPolicy::isSafe("uploads/{$ids['own']}x/p.png", $storeA->id), 'digits followed by letters are not a store id');
    }

    public function test_a_product_cannot_take_another_tenants_file_or_a_shared_one_as_its_cover(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        foreach (["uploads/{$storeB->id}/victim.png", "uploads/uploads/{$storeB->id}/g/victim.png", 'uploads/profile/avatar.png', 'uploads/logo/logo-dark.png'] as $value) {
            try {
                $this->productIn($storeA, ['cover_image_path' => $value]);
                $refused = false;
            } catch (ValidationException $e) {
                $refused = isset($e->errors()['cover_image_path']);
            }

            $this->assertTrue($refused, "A product of store {$storeA->id} was saved with cover_image_path = {$value}.");
        }

        $this->assertNotNull($this->productIn($storeA, ['cover_image_path' => "uploads/{$storeA->id}/mine.png"])->id, 'its own folder is still accepted');
    }

    public function test_a_gallery_image_cannot_name_another_tenants_file(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $product = $this->productIn($storeA);

        $this->assertNotNull(ProductImage::create(['product_id' => $product->id, 'image_path' => "uploads/uploads/{$storeA->id}/g.png"])->id);

        $this->expectException(ValidationException::class);
        ProductImage::create(['product_id' => $product->id, 'image_path' => "uploads/uploads/{$storeB->id}/victim.png"]);
    }

    public function test_a_non_string_is_not_a_path(): void
    {
        $this->assertFalse(StoredPathPolicy::isSafe(['uploads/x']));
        $this->assertFalse(StoredPathPolicy::isSafe(12));
    }

    // ---------------------------------------------------------------------------------------------
    // the write-side rule: what the ImportExport CSV importer can no longer plant
    // ---------------------------------------------------------------------------------------------

    public static function poisonedProductColumns(): array
    {
        return [['cover_image_path', '.env'], ['cover_image_path', 'config/*'], ['cover_image_path', '../../.env'], ['downloadable_product', 'database/database.sqlite'],
            ['downloadable_product', '/etc/passwd']];
    }

    /** @dataProvider poisonedProductColumns */
    public function test_a_product_cannot_be_created_with_a_path_column_outside_the_upload_roots(string $column, string $value): void
    {
        [, $store] = $this->makeTenant();

        try {
            $this->productIn($store, [$column => $value]);
            $refused = false;
        } catch (ValidationException $e) {
            $refused = isset($e->errors()[$column]);
        }

        $this->assertTrue($refused, "A product was saved with {$column} = {$value}.");
    }

    public function test_the_importers_row_shape_is_checked_as_the_importer_builds_it(): void
    {
        [, $store] = $this->makeTenant();
        $row = ['cover_image' => '.env'];

        $this->expectException(ValidationException::class);

        // exactly ProductsImportData::model(): `new Product([...'cover_image_path' => $row['cover_image'] ?? <default>...])->save()`
        $product = new Product(['name' => 'csv', 'slug' => 'csv-' . uniqid(), 'cover_image_path' => $row['cover_image'] ?? StoredPathPolicy::IMPORT_EXPORT_DEFAULT,
            'cover_image_url' => $row['cover_image'], 'store_id' => $store->id]);
        $product->save();
    }

    public function test_the_importers_default_image_is_still_accepted(): void
    {
        [, $store] = $this->makeTenant();

        $product = $this->productIn($store, ['cover_image_path' => StoredPathPolicy::IMPORT_EXPORT_DEFAULT]);

        $this->assertNotNull($product->id);
    }

    public function test_a_video_files_preview_content_is_a_path_and_a_video_urls_is_not(): void
    {
        [, $store] = $this->makeTenant();

        $url = $this->productIn($store, ['preview_type' => 'Video URL', 'preview_content' => 'https://www.youtube.com/watch?v=abc']);
        $this->assertNotNull($url->id, 'A video URL is not a path and must not be refused.');

        $this->expectException(ValidationException::class);
        $this->productIn($store, ['preview_type' => 'Video File', 'preview_content' => '.env']);
    }

    /**
     * `other_description_api` has no migration in this repository (a Gate F schema gap -- five readers, no writer), so the column cannot be
     * persisted in the test database; the rule is exercised on the model as the saving event would see it.
     */
    public function test_a_photo_upload_field_of_the_description_json_is_checked(): void
    {
        $json = fn (string $value) => json_encode(['product-other-description' => [['field_type' => 'photo upload', 'name' => 'x', 'value' => $value],
            ['field_type' => 'text', 'name' => 't', 'value' => '../../not-a-path-because-it-is-text']]]);
        $product = fn (string $value) => (new Product())->setAttribute('other_description_api', $json($value));

        StoredPathPolicy::product($product('uploads/1/a.png'));                          // accepted: no exception
        $this->assertTrue(true);

        $this->expectException(ValidationException::class);
        StoredPathPolicy::product($product('.env'));
    }

    public function test_only_a_changed_path_is_judged_so_a_legacy_row_stays_editable(): void
    {
        [, $store] = $this->makeTenant();
        $product = $this->productIn($store);
        DB::table('products')->where('id', $product->id)->update(['cover_image_path' => 'legacy-flat-name.png']);   // data from before the rule

        $product = Product::withoutGlobalScopes()->find($product->id);
        $product->product_stock = 9;
        $product->save();                                                                                       // an unrelated edit still saves

        $this->assertSame(9, (int) DB::table('products')->where('id', $product->id)->value('product_stock'));

        $product->cover_image_path = '.env';                                                                    // changing the column is judged
        $this->expectException(ValidationException::class);
        $product->save();
    }

    public function test_a_product_image_row_is_checked(): void
    {
        [, $store] = $this->makeTenant();
        $product = $this->productIn($store);

        $this->assertNotNull(ProductImage::create(['product_id' => $product->id, 'image_path' => 'uploads/uploads/' . $store->id . '/a.png'])->id);

        $this->expectException(ValidationException::class);
        ProductImage::create(['product_id' => $product->id, 'image_path' => 'config/app.php']);
    }

    public function test_the_sign_in_image_settings_the_vendored_handlers_unlink_are_checked_and_other_settings_are_not(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $make = fn (string $name, string $value) => Setting::create(['name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id]);

        $this->assertNotNull($make('linkedin_sign_in_image', 'uploads/' . $store->id . '/in.png')->id);
        $this->assertNotNull($make('theme_note', '.env is only text here')->id, 'An ordinary setting was judged as a path.');

        foreach (['linkedin_sign_in_image', 'facebook_sign_in_image', 'Google_Sign_In_Image'] as $name) {
            try {
                $make($name, '.env');
                $this->fail('A path outside the upload roots was stored in ' . $name);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('value', $e->errors());
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // the host deleters
    // ---------------------------------------------------------------------------------------------

    /**
     * A row poisoned BEFORE the write-side rule existed (or by a raw write): the web product delete removes the row and unlinks nothing outside uploads/.
     */
    public function test_deleting_a_product_whose_stored_paths_are_poisoned_unlinks_nothing_outside_uploads(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Products');
        $cover = $this->decoy('p1sec28-cover.txt');
        $download = $this->decoy('p1sec28-download.txt');
        $gallery = $this->decoy('p1sec28-gallery.txt');
        $product = $this->productIn($store);
        DB::table('products')->where('id', $product->id)->update(['cover_image_path' => 'p1sec28-cover.txt', 'downloadable_product' => 'p1sec28-download.txt']);
        DB::table('product_images')->insert(['product_id' => $product->id, 'image_path' => 'p1sec28-gallery.txt', 'image_url' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($merchant)->delete('/product/' . $product->id);

        $this->assertNotSame(500, $response->status(), substr((string) $response->getContent(), 0, 300));
        $this->assertNull(DB::table('products')->where('id', $product->id)->first(), 'Premise: the product row is deleted.');
        foreach ([$cover, $download, $gallery] as $file) {
            $this->assertFileExists($file, 'A file outside uploads/ was unlinked through a poisoned product row.');
        }
    }

    public function test_deleting_a_product_still_unlinks_its_own_upload(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Products');
        $dir = base_path('uploads/' . $store->id);
        @mkdir($dir, 0777, true);
        $file = $dir . '/p1sec28-own.png';
        file_put_contents($file, 'x');
        $this->scratch[] = $file;
        $product = $this->productIn($store, ['cover_image_path' => 'uploads/' . $store->id . '/p1sec28-own.png']);

        $this->actingAs($merchant)->delete('/product/' . $product->id);

        $this->assertFileDoesNotExist($file, 'Premise: the product\'s own upload must still be removed.');
        @rmdir($dir);
    }

    public function test_a_file_in_another_stores_folder_is_not_unlinked_through_my_row(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();
        $this->grantPermission($merchant, 'Delete Products');
        $dir = base_path('uploads/' . $theirs->id);
        @mkdir($dir, 0777, true);
        $victimFile = $dir . '/p1sec28-victim.png';
        file_put_contents($victimFile, 'x');
        $this->scratch[] = $victimFile;
        $product = $this->productIn($store);
        DB::table('products')->where('id', $product->id)->update(['cover_image_path' => 'uploads/' . $theirs->id . '/p1sec28-victim.png']);

        $this->actingAs($merchant)->delete('/product/' . $product->id);

        $this->assertFileExists($victimFile, 'A poisoned row unlinked a file in ANOTHER store\'s upload folder.');
        @rmdir($dir);
    }

    public function test_a_shared_file_at_the_repository_root_such_as_the_default_avatar_is_never_unlinked(): void
    {
        $avatar = $this->decoy('p1sec28-avatar.png');

        $this->assertFalse(StoredUpload::delete('p1sec28-avatar.png', 1));
        $this->assertFalse(StoredUpload::delete(StoredPathPolicy::IMPORT_EXPORT_DEFAULT, 1), 'ImportExport\'s bundled default image was treated as a product\'s own file.');
        $this->assertFileExists($avatar);
        $this->assertFileExists(base_path(StoredPathPolicy::IMPORT_EXPORT_DEFAULT), 'Premise: the bundled default image exists in this checkout.');
    }

    // ---------------------------------------------------------------------------------------------
    // the census
    // ---------------------------------------------------------------------------------------------

    /**
     * Every `File::delete(` / `unlink(` in app/, by file and count. A new unlink of a path that came from the database has to go through
     * StoredUpload; this list is the places that legitimately do not, each with its reason.
     */
    public function test_the_filesystem_removals_in_app_are_exactly_these(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // comments are not calls
            $code = preg_replace('#/\*.*?\*/|(?<!:)//[^\n]*|^\s*\*[^\n]*#ms', '', $source);
            $n = preg_match_all('/\b(?:\\\\?File::delete|unlink)\s*\(/', $code);
            if ($n) {
                $found[substr($file->getPathname(), strlen(base_path()) + 1)] = $n;
            }
        }
        ksort($found);

        $this->assertSame([
            'app/Helper/helper.php' => 1,                              // rmdir-style helper over a directory the caller built (not a stored path)
            'app/Http/Controllers/AddonController.php' => 1,           // addon installer: temp tree it just created
            'app/Http/Controllers/LanguageController.php' => 1,        // operator-only language file name
            'app/Http/Controllers/ModuleController.php' => 1,          // module installer: temp tree it just created
            'app/Http/Controllers/SettingController.php' => 3,         // logo files: the path is a constant 'uploads/logo/<fixed name>', not a stored value
            'app/Models/Utility.php' => 3,                             // rmdir-style directory helper + the two loops that unlink ONLY what StoredUpload::resolveAll() returned
            'app/Services/StoredUpload.php' => 1,                      // THE deleter
        ], $found, 'A filesystem removal was added or removed in app/. A path that came from the database must go through StoredUpload::delete().');
    }
}
