<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use App\Services\StoredUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * P1-SEC-18 F6 -- a file path out of the database is untrusted input to the filesystem.
 * =============================================================================================
 *
 * `Utility::changeStorageLimit($owner, $path)` is called with a stored path by ~35 handlers and used to
 * `File::glob(base_path($path))` and `File::delete()` every match. A tenant can write the stored value (the generic settings
 * writers take any name/value), so `config/*` deleted the application's config. WebSettingsSecretsTest drives the real
 * routes (`/customize-settings` then `/business-settings`); this file pins the helper that now stands in front of every caller.
 *
 * `uploads/` IS A TRACKED DIRECTORY in this repository (407 files, `uploads/2/...`, `uploads/11/...`). The test database's store ids
 * start at 1, so a fixture folder `uploads/<store id>` would collide with real tracked folders -- and a teardown that deleted it
 * did delete 72 tracked files from the working tree (restored with `git checkout -- uploads/`). So the stores below are re-keyed
 * to ids no real folder uses, and teardown removes ONLY the fixture folders named here and never anything it did not create.
 */
class StoredUploadTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private User $foreignMerchant;
    private Store $foreignStore;
    private bool $createdUploadsRoot = false;

    private const OWN_STORE_ID = 987001;
    private const FOREIGN_STORE_ID = 987002;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdUploadsRoot = ! is_dir(base_path('uploads'));
        // Fixture folders must not collide with the real, tracked uploads/<id> folders -- see the class docblock.
        foreach ([self::OWN_STORE_ID, self::FOREIGN_STORE_ID] as $id) {
            $this->assertDirectoryDoesNotExist(base_path('uploads/' . $id));
            $this->assertDirectoryDoesNotExist(base_path('uploads/uploads/' . $id));
        }

        [$this->merchant, $this->store] = $this->makeTenant();
        [$this->foreignMerchant, $this->foreignStore] = $this->makeTenant();
        $this->store = $this->rekey($this->merchant, $this->store, self::OWN_STORE_ID);
        $this->foreignStore = $this->rekey($this->foreignMerchant, $this->foreignStore, self::FOREIGN_STORE_ID);
    }

    private function rekey(User $merchant, Store $store, int $newId): Store
    {
        DB::table('stores')->where('id', $store->id)->update(['id' => $newId]);
        DB::table('users')->where('id', $merchant->id)->update(['current_store' => $newId]);

        return Store::findOrFail($newId);
    }

    protected function tearDown(): void
    {
        foreach ([self::OWN_STORE_ID, self::FOREIGN_STORE_ID] as $id) {
            File::deleteDirectory(base_path('uploads/' . $id));
            File::deleteDirectory(base_path('uploads/uploads/' . $id));   // the product gallery's doubled prefix
        }
        File::deleteDirectory(base_path('uploads/zz-sec18'));
        File::deleteDirectory(base_path('uploads-zz-sec18'));
        File::deleteDirectory(base_path('storage/framework/testing/sec18'));
        if ($this->createdUploadsRoot && is_dir(base_path('uploads')) && count(scandir(base_path('uploads'))) === 2) {
            rmdir(base_path('uploads'));
        }

        parent::tearDown();
    }

    private function file(string $relative, string $body = 'x'): string
    {
        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), $body);

        return $relative;
    }

    public function test_a_literal_file_in_the_owners_own_store_folder_resolves(): void
    {
        $path = $this->file('uploads/' . $this->store->id . '/logo.png');

        $this->assertSame(realpath(base_path($path)), StoredUpload::resolve($path, $this->merchant->id));
    }

    public function test_another_tenants_store_folder_does_not_resolve_for_this_owner(): void
    {
        $path = $this->file('uploads/' . $this->foreignStore->id . '/logo.png');

        $this->assertNull(StoredUpload::resolve($path, $this->merchant->id));
        $this->assertFileExists(base_path($path));
    }

    public function test_the_platform_operator_may_touch_any_stores_folder(): void
    {
        $operator = $this->makePlatformAdmin();
        $path = $this->file('uploads/' . $this->foreignStore->id . '/logo.png');

        $this->assertSame(realpath(base_path($path)), StoredUpload::resolve($path, $operator->id));
    }

    public function test_a_shared_non_numeric_folder_resolves(): void
    {
        $path = $this->file('uploads/zz-sec18/shared.png');

        $this->assertNotNull(StoredUpload::resolve($path, $this->merchant->id));
    }

    /** The product gallery lives at uploads/uploads/<store id>/... because its helper prepends `uploads/` to a directory that already has it. */
    public function test_the_doubled_uploads_prefix_of_the_product_gallery_is_still_a_tenant_folder(): void
    {
        $own = $this->file('uploads/uploads/' . self::OWN_STORE_ID . '/gallery.jpg');
        $foreign = $this->file('uploads/uploads/' . self::FOREIGN_STORE_ID . '/gallery.jpg');

        $this->assertNotNull(StoredUpload::resolve($own, $this->merchant->id), 'The owner\'s own gallery image must resolve.');
        $this->assertNull(StoredUpload::resolve($foreign, $this->merchant->id), 'Another tenant\'s gallery image resolved.');
        $this->assertFalse(StoredUpload::delete($foreign, $this->merchant->id));
        $this->assertFileExists(base_path($foreign));

        $operator = $this->makePlatformAdmin();
        $this->assertNotNull(StoredUpload::resolve($foreign, $operator->id));
    }

    public function test_a_stored_value_with_a_nul_byte_is_not_a_path_and_does_not_throw(): void
    {
        $this->assertNull(StoredUpload::resolve("uploads/zz-sec18/a.png\0.txt", $this->merchant->id));
        $this->assertSame([], StoredUpload::resolveAll("\0", $this->merchant->id));
        $this->assertFalse(StoredUpload::delete("uploads/\0", $this->merchant->id));
    }

    public static function refusedPaths(): array
    {
        return [
            'a glob pattern' => ['uploads/zz-sec18/*'],
            'a glob over every store' => ['uploads/*/*'],
            'a question-mark pattern' => ['uploads/zz-sec18/a?.png'],
            'a traversal out of uploads' => ['uploads/../storage/framework/testing/sec18/outside.txt'],
            'a path outside uploads' => ['storage/framework/testing/sec18/outside.txt'],
            'a sibling directory sharing the prefix' => ['uploads-zz-sec18/x.png'],
            'a directory' => ['uploads/zz-sec18'],
            'the application config' => ['config/app.php'],
            'the environment file' => ['.env.example'],
            'an absolute path' => ['/etc/passwd'],
            'empty' => [''],
            'whitespace' => ['   '],
        ];
    }

    /**
     * @dataProvider refusedPaths
     */
    public function test_a_path_that_is_not_one_literal_file_in_uploads_never_resolves(string $path): void
    {
        $this->file('uploads/zz-sec18/ab.png');
        $this->file('uploads-zz-sec18/x.png');
        $this->file('storage/framework/testing/sec18/outside.txt');

        $this->assertNull(StoredUpload::resolve($path, $this->merchant->id), "'{$path}' resolved.");
        $this->assertSame([], StoredUpload::resolveAll($path, $this->merchant->id));
    }

    public function test_a_symlink_inside_uploads_that_points_outside_does_not_resolve(): void
    {
        $this->file('storage/framework/testing/sec18/outside.txt');
        File::ensureDirectoryExists(base_path('uploads/zz-sec18'));
        $link = base_path('uploads/zz-sec18/link.txt');
        if (! @symlink(base_path('storage/framework/testing/sec18/outside.txt'), $link)) {
            $this->markTestSkipped('This filesystem cannot create symlinks.');
        }

        $this->assertNull(StoredUpload::resolve('uploads/zz-sec18/link.txt', $this->merchant->id));
        $this->assertFileExists(base_path('storage/framework/testing/sec18/outside.txt'));
    }

    public function test_delete_unlinks_only_what_resolves(): void
    {
        $inside = $this->file('uploads/' . $this->store->id . '/old.png');
        $outside = $this->file('storage/framework/testing/sec18/outside.txt');
        $foreign = $this->file('uploads/' . $this->foreignStore->id . '/theirs.png');

        $this->assertTrue(StoredUpload::delete($inside, $this->merchant->id));
        $this->assertFalse(StoredUpload::delete($outside, $this->merchant->id));
        $this->assertFalse(StoredUpload::delete($foreign, $this->merchant->id));

        $this->assertFileDoesNotExist(base_path($inside));
        $this->assertFileExists(base_path($outside));
        $this->assertFileExists(base_path($foreign));
    }

    // ---------------------------------------------------------------------------------------------
    // the function the ~35 callers use
    // ---------------------------------------------------------------------------------------------

    public function test_change_storage_limit_still_frees_the_quota_and_unlinks_a_legitimate_file(): void
    {
        $this->merchant->storage_limit = 10;
        $this->merchant->save();
        $path = $this->file('uploads/' . $this->store->id . '/old.png', str_repeat('x', 1048576)); // 1 MiB

        Utility::changeStorageLimit($this->merchant->id, $path);

        $this->assertFileDoesNotExist(base_path($path), 'Premise: a legitimate stored file is still removed.');
        $this->assertEquals(9.0, (float) User::find($this->merchant->id)->storage_limit, 'The freed megabyte must come off the owner\'s usage.');
    }

    public function test_change_storage_limit_does_not_expand_a_glob(): void
    {
        $this->merchant->storage_limit = 10;
        $this->merchant->save();
        $a = $this->file('uploads/zz-sec18/a.png');
        $b = $this->file('uploads/zz-sec18/b.png');
        $outside = $this->file('storage/framework/testing/sec18/outside.txt');

        // SANDBOX PATTERNS ONLY. This test exists to prove a glob is not expanded; if that control ever regresses it must not
        // be able to unlink anything but fixtures. (An earlier draft also tried `*` and `uploads/*/*`: with the control removed
        // for a mutation check, those deleted the repository root and 131 tracked upload files.)
        foreach (['uploads/zz-sec18/*', 'uploads/zz-sec18/?.png', 'storage/framework/testing/sec18/*'] as $pattern) {
            Utility::changeStorageLimit($this->merchant->id, $pattern);
        }

        foreach ([$a, $b, $outside] as $file) {
            $this->assertFileExists(base_path($file), "changeStorageLimit unlinked {$file} for a glob.");
        }
        $this->assertEquals(10.0, (float) User::find($this->merchant->id)->storage_limit);
    }

    // the sibling the second review found: ProductController::destroy, Product::productImageDelete and two modules call it

    public function test_change_product_storage_limit_frees_the_quota_and_unlinks_literal_files_only(): void
    {
        $this->merchant->storage_limit = 10;
        $this->merchant->save();
        $own = $this->file('uploads/uploads/' . self::OWN_STORE_ID . '/cover.jpg', str_repeat('x', 1048576));
        $theirs = $this->file('uploads/uploads/' . self::FOREIGN_STORE_ID . '/cover.jpg', str_repeat('x', 1048576));
        $a = $this->file('uploads/zz-sec18/a.png');
        $outside = $this->file('storage/framework/testing/sec18/outside.txt');

        // SANDBOX PATTERNS ONLY (see test_change_storage_limit_does_not_expand_a_glob).
        Utility::changeproductStorageLimit($this->merchant->id, [$theirs, 'uploads/zz-sec18/*', 'storage/framework/testing/sec18/*', $outside, $own]);

        $this->assertFileDoesNotExist(base_path($own), 'Premise: a legitimate stored product path is still removed.');
        $this->assertEquals(9.0, (float) User::find($this->merchant->id)->storage_limit);
        foreach ([$theirs, $a, $outside] as $file) {
            $this->assertFileExists(base_path($file), "changeproductStorageLimit unlinked {$file}.");
        }
    }

    public function test_change_storage_limit_never_touches_another_tenants_folder(): void
    {
        $theirs = $this->file('uploads/' . $this->foreignStore->id . '/theirs.png', str_repeat('x', 1048576));

        Utility::changeStorageLimit($this->merchant->id, $theirs);

        $this->assertFileExists(base_path($theirs));
    }
}
