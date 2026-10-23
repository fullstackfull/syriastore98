<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * NEW-get-file-blanks-every-url-without-storage-setting -- `get_file()` ANSWERED '' FOR EVERY PATH.
 * =================================================================================================
 *
 * `app/Helper/helper.php`'s `get_file()` turns a stored relative path into a URL, and it is called from
 * the shared layout partials, so it runs on essentially every panel page. It selected its storage branch
 * with
 *
 *     if (isset($settings) && (count($settings) > 0) && $settings['storage_setting'] == 'wasabi')
 *
 * where `$settings` is EVERY `settings` row of the SUPER ADMIN's current store. `count() > 0` and
 * "`storage_setting` is present" are different questions: one row of any other name satisfies the count.
 * PHP 8 then raises "Undefined array key", Laravel's error handler promotes the warning to an
 * ErrorException, and `get_file()`'s own `catch (\Throwable) { return ''; }` swallows it -- so the
 * function returns an EMPTY STRING rather than the local URL its `else` limb exists to produce. Every
 * logo, avatar, product image and invoice mark resolves to `src=""` at once, silently, with nothing in
 * the log.
 *
 * `storage_setting` is NOT NULL nowhere and the storage screen writes it only once storage is configured,
 * so a platform whose operator has never opened that screen -- while having any other setting row at all
 * -- is in exactly this state. FOUND while fixing P1-SEC-11 criterion 4: the order documents' new
 * store-correct invoice logo came back empty for this reason and not for the one under test.
 */
class StoredFileUrlResolutionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function setting(int $storeId, int $createdBy, string $name, string $value): void
    {
        DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $storeId, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now()]);
        (new \App\Models\Setting)->flushCache();   // Setting is Cachable; a raw write leaves reads stale
    }

    /** The super admin whose `current_store` settings `get_file()` reads, with that store selected. */
    private function platformWithStore(): array
    {
        $operator = $this->makePlatformAdmin();
        $store = $this->makeStore($operator);
        \App\Models\User::where('id', $operator->id)->update(['current_store' => $store->id]);
        (new \App\Models\User)->flushCache();

        return [$operator, $store];
    }

    /**
     * THE DEFECT ITSELF. One unrelated settings row is enough: remove the guard and this returns ''.
     */
    public function test_a_store_with_settings_but_no_storage_setting_still_resolves_a_local_url(): void
    {
        [$operator, $store] = $this->platformWithStore();
        $this->setting($store->id, $operator->id, 'invoice_logo', 'uploads/9/anything.png');

        $this->assertSame(
            [],
            DB::table('settings')->where('store_id', $store->id)->where('name', 'storage_setting')->get()->all(),
            'Premise: the state under test is a store that HAS settings rows and no `storage_setting` among them.'
        );

        $this->assertSame(url('uploads/7/product.png'), get_file('uploads/7/product.png'),
            'get_file() returned an empty string, so every image on the page renders as src="".');
    }

    /**
     * And the empty answer is not confined to the one path: the count is a property of the store, so the
     * blanking is total. Asserted on three unrelated paths from the three callers that matter most.
     */
    public function test_the_blanking_covers_every_path_not_just_one(): void
    {
        [$operator, $store] = $this->platformWithStore();
        $this->setting($store->id, $operator->id, 'company_name', 'Anything At All');

        foreach (['uploads/avatar/1.png', 'uploads/products/sku.jpg', 'storage/uploads/logo/logo.png'] as $path) {
            $this->assertSame(url($path), get_file($path), "get_file() blanked {$path}.");
        }
    }

    /**
     * UNCHANGED WHERE THE KEY EXISTS -- the whole point of the fix being narrow. 'local' and an explicitly
     * empty value both take the same `else` limb they always did.
     */
    public function test_an_explicit_local_storage_setting_is_unchanged(): void
    {
        [$operator, $store] = $this->platformWithStore();
        $this->setting($store->id, $operator->id, 'storage_setting', 'local');

        $this->assertSame(url('uploads/7/product.png'), get_file('uploads/7/product.png'));
    }

    public function test_an_empty_storage_setting_is_treated_as_local(): void
    {
        [$operator, $store] = $this->platformWithStore();
        $this->setting($store->id, $operator->id, 'storage_setting', '');

        $this->assertSame(url('uploads/7/product.png'), get_file('uploads/7/product.png'));
    }

    /**
     * A STORE WITH NO SETTINGS AT ALL was never affected -- `count($settings) > 0` short-circuited. Stated
     * so the test file records which configuration the defect needed, and so a future reader does not
     * "reproduce" it against the one state that always worked.
     */
    public function test_a_store_with_no_settings_at_all_was_never_affected(): void
    {
        $this->platformWithStore();

        $this->assertSame(url('uploads/7/product.png'), get_file('uploads/7/product.png'));
    }

    /**
     * AND THE REMOTE BRANCHES STILL ROUTE REMOTELY. Without this, the guard could have been written as a
     * blanket `local` and these tests would not have noticed.
     */
    public function test_an_s3_storage_setting_still_takes_the_remote_branch(): void
    {
        [$operator, $store] = $this->platformWithStore();
        foreach (['storage_setting' => 's3', 's3_key' => 'KEY', 's3_secret' => 'SECRET', 's3_region' => 'eu-west-1', 's3_bucket' => 'a-bucket'] as $name => $value) {
            $this->setting($store->id, $operator->id, $name, $value);
        }

        $url = get_file('uploads/7/product.png');

        $this->assertNotSame(url('uploads/7/product.png'), $url, 'an s3 store resolved to a local URL');
        $this->assertStringContainsString('a-bucket', (string) $url);
    }

    public function test_a_wasabi_storage_setting_still_takes_the_remote_branch(): void
    {
        [$operator, $store] = $this->platformWithStore();
        foreach (['storage_setting' => 'wasabi', 'wasabi_key' => 'KEY', 'wasabi_secret' => 'SECRET', 'wasabi_region' => 'eu-central-2', 'wasabi_bucket' => 'a-bucket'] as $name => $value) {
            $this->setting($store->id, $operator->id, $name, $value);
        }

        $this->assertSame('https://s3.eu-central-2.wasabisys.com/a-bucket/uploads/7/product.png', get_file('uploads/7/product.png'));
    }

    /**
     * An empty path is returned verbatim, before any branch -- which is why `DocumentDirection::
     * invoiceLogoFor()` must not pass an empty stored value through and expect a URL back.
     */
    public function test_an_empty_path_is_returned_verbatim(): void
    {
        $this->platformWithStore();

        $this->assertSame('', get_file(''));
    }
}
