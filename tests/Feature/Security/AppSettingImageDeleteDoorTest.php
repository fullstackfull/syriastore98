<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `AppSettingController::image_delete` — AN UNLINK KEYED ON REQUEST INPUT. `GAP-268`'s LAST ONE.
 * =================================================================================================
 *
 * This was the ninth and final entry in `GAP-268`'s declared set, and the only one of the original
 * 73 that was BROKEN IN USE rather than orphaned: `resources/views/AppSetting/mobile_content
 * .blade.php` calls `route('product.image.delete')` inside a RENDERED view, so withdrawing the
 * route throws `RouteNotFoundException` at render time and 500s the whole Mobile Screen Content
 * page. It needed the METHOD, which is why it survived a routing batch.
 *
 * THE SHAPE IS THE WHOLE JOB. A handler that unlinks a path named by `$request->image` is
 * P1-SEC-71 — the finding where stored ticket edits landed in a folder no store's id was part of.
 * So the control is layered, and each layer is named here with what it alone would and would not
 * stop, because "it is guarded" is not a description.
 *
 *   1. `instanceof User` FIRST, before anything reads an identity. This is not ceremony on this
 *      handler: `getCurrentStore()` reads `auth()->user()` and, for any principal whose `type` is
 *      `super admin`, RETURNS THE HARD-CODED LITERAL 1 (`app/Helper/helper.php`). `customers` has
 *      a fillable `type` column, so a `Customer` row typed `super admin` would be handed store 1's
 *      folder by that helper. The class check is what stops the question being asked at all.
 *   2. Operator-first, then `Manage Store Setting` — the permission this controller's own `index()`
 *      uses. Operator-first because Laratrust has no super-admin bypass here, so `isAbleTo()`
 *      alone refuses the platform operator.
 *   3. FAIL CLOSED ON A STORE THAT IS NOT A POSITIVE INTEGER. `getCurrentStore()` answers `null`
 *      for a merchant with no store and no `current_store`. A null store silently makes the prefix
 *      in layer 4 `uploads/` — the root — which is how P1-SEC-71 happened.
 *   4. THE PREFIX COMES FROM THE PRINCIPAL, NOT THE REQUEST. Only the leaf is caller-supplied: the
 *      path must begin `uploads/<that store id>/`. This layer is what covers the DEGENERATE
 *      STORED DATA — the uploader is `$dir = 'uploads/' . getCurrentStore()`, so a file uploaded
 *      while the store was null sits at `uploads/<file>` with NO numeric segment, and
 *      `StoredUpload`'s tenant rule (layer 5) skips a first segment that is not digits. Without
 *      layer 4 any merchant could delete those.
 *   5. `StoredUpload::delete($path, $user->creatorId())` IS THE REAL CONTROL, and it is reused
 *      rather than re-implemented — it is what `Product`, `Category` and `Utility` already delete
 *      through. It `realpath()`s both sides, so `..`, a symlink and a percent-decoded traversal all
 *      collapse before the comparison; requires the result to sit strictly under
 *      `base_path('uploads')`; refuses the platform defaults; and re-reads the tenant segment FROM
 *      THE REAL PATH, so `uploads/A/../B/x.png` is judged as store B. Layer 4's `str_starts_with`
 *      would pass that string; layer 5 is why it still fails.
 *
 * WHAT IS DELIBERATELY NOT DONE: the handler does not touch the stored `theme_json`. The view's
 * own success callback re-submits the form (`$('#Main_Page_Content_web_post').find('.submit_form')
 * .click()`), so the record is updated by that resubmission. A handler that also rewrote the JSON
 * would be a second writer of the same document.
 *
 * AND ONE MEASURED FACT THAT CHANGES HOW MUCH THIS IS WORTH, recorded because it would otherwise
 * look like a live feature being restored: THE UI CANNOT REACH THIS ENDPOINT. `image_delete` is
 * matched by `$('body').on('click', '.image_delete', ...)`, and `image_delete` appears exactly
 * TWICE in the whole Blade tree — both times as that selector. NO element anywhere carries
 * `class="image_delete"`. The visible delete affordance in that view is
 * `class="action-item deleteRecord" data-name="{{ $file_pathh }}"`, and `.deleteRecord` has no
 * handler in that file either (the `.deleteRecord` handlers live in `resources/views/product/*`).
 * So both halves of the wiring are broken, in opposite directions. This handler therefore removes
 * a guaranteed 500 and restores no capability a merchant currently has; the broken wiring is
 * reported separately rather than fixed here, because which affordance is meant to win is a UI
 * decision and this is a security fix.
 */
class AppSettingImageDeleteDoorTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var list<string> absolute paths this test created, removed in tearDown */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->made = [];

        parent::tearDown();
    }

    /** Create a real probe file at `uploads/<storeId>/<name>` and return its RELATIVE path. */
    private function probeFile(int $storeId, string $name): string
    {
        $dir = base_path('uploads/' . $storeId);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $absolute = $dir . '/' . $name;
        file_put_contents($absolute, 'probe');
        $this->made[] = $absolute;

        return 'uploads/' . $storeId . '/' . $name;
    }

    private function postDelete(string $actorKind, $actor, string $image)
    {
        return $actorKind === 'customer'
            ? $this->actingAs($actor, 'customers')->call('POST', '/product-image-delete', ['image' => $image])
            : $this->actingAs($actor)->call('POST', '/product-image-delete', ['image' => $image]);
    }

    /**
     * PREMISE. The method exists and the route reaches it. Without this, every refusal below could
     * be the `BadMethodCallException` 500 the handler was added to remove, and the file would pass
     * for the worst possible reason.
     */
    #[Test]
    public function test_premise_the_handler_exists_and_is_routed(): void
    {
        $this->assertTrue(
            method_exists(\App\Http\Controllers\AppSettingController::class, 'image_delete'),
            'PREMISE: AppSettingController::image_delete() does not exist, so every refusal '
            . 'asserted below is really a BadMethodCallException 500 and proves nothing.'
        );

        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('product.image.delete');

        $this->assertNotNull($route, 'PREMISE: the route is gone.');
        $this->assertSame(
            \App\Http\Controllers\AppSettingController::class . '@image_delete',
            $route->getActionName(),
            'PREMISE: product.image.delete no longer points at image_delete.'
        );
    }

    /** THE CAPABILITY. A merchant deletes their OWN store's image. */
    #[Test]
    public function test_a_merchant_can_delete_an_image_in_their_own_store_folder(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $relative = $this->probeFile($store->id, 'ws4-own-' . bin2hex(random_bytes(4)) . '.png');
        $absolute = base_path($relative);

        $this->assertFileExists($absolute, 'premise: the probe file was not created.');

        $response = $this->postDelete('user', $merchant, $relative);

        $this->assertSame(200, $response->getStatusCode(),
            'the merchant could not delete their own image: ' . $response->getContent());

        $this->assertFileDoesNotExist($absolute,
            'the handler answered 200 and the file is still there, so it deleted nothing and the '
            . 'refusals below would pass against a handler that never unlinks anything.');
    }

    /**
     * THE DEFECT. Another tenant's image, named directly. The one assertion that matters is that
     * the FILE SURVIVES — a status code alone would not catch a handler that refuses loudly after
     * unlinking.
     */
    #[Test]
    public function test_a_merchant_cannot_delete_another_stores_image(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Store Setting');

        [, $storeB] = $this->makeTenant();

        $victim = $this->probeFile($storeB->id, 'ws4-victim-' . bin2hex(random_bytes(4)) . '.png');
        $absolute = base_path($victim);

        $this->postDelete('user', $merchantA, $victim);

        $this->assertFileExists($absolute,
            'CROSS-TENANT DELETE: a merchant of another store unlinked store ' . $storeB->id
            . '\'s file by naming its path in the request. The prefix must come from the '
            . 'authenticated principal and the unlink must go through StoredUpload with '
            . 'creatorId().');
    }

    /** The same thing wearing a traversal, which `str_starts_with` on its own would admit. */
    #[Test]
    public function test_a_traversal_out_of_the_callers_own_folder_is_refused(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Store Setting');

        [, $storeB] = $this->makeTenant();

        $victim = $this->probeFile($storeB->id, 'ws4-trav-' . bin2hex(random_bytes(4)) . '.png');
        $absolute = base_path($victim);

        $traversal = 'uploads/' . $storeA->id . '/../' . $storeB->id . '/' . basename($victim);

        $this->postDelete('user', $merchantA, $traversal);

        $this->assertFileExists($absolute,
            'TRAVERSAL: `' . $traversal . '` begins with the caller\'s own prefix and resolves '
            . 'into another store. A prefix check alone cannot see that; the unlink has to '
            . 'realpath() and re-read the tenant segment from the resolved path.');
    }

    /**
     * And a path that leaves the uploads root altogether.
     *
     * THE VICTIM IS A FILE THIS TEST CREATES, NOT A REPOSITORY FILE, and that is a correction
     * rather than a preference. The first version of this case named `composer.json` as the
     * victim -- which DID detect the defect, but when the mutation that proves layer 5 is
     * load-bearing was run (replacing `StoredUpload::delete()` with a raw
     * `File::delete(base_path($path))`), THE HANDLER DELETED THE REAL `composer.json` and every
     * later `php artisan` call died in `Application::getNamespace()`. The assertion failed
     * correctly and the working tree was damaged anyway. A test for a destructive handler must
     * aim it at something disposable: a correct refusal and a successful deletion look the same
     * to the filesystem afterwards, so the only safe victim is one the test owns.
     */
    #[Test]
    public function test_a_path_outside_the_uploads_root_is_refused(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        /* Outside `uploads/`, inside the repository, and entirely this test's own. */
        $name = 'ws4-outside-probe-' . bin2hex(random_bytes(6)) . '.tmp';
        $absolute = base_path($name);
        file_put_contents($absolute, 'probe');
        $this->made[] = $absolute;

        foreach ([
            $name,
            'uploads/' . $storeA->id . '/../../' . $name,
            '../' . basename(base_path()) . '/' . $name,
        ] as $candidate) {
            $this->postDelete('user', $merchant, $candidate);

            $this->assertFileExists($absolute,
                'ARBITRARY FILE DELETE: `' . $candidate . '` reached a file outside '
                . 'base_path(\'uploads\'). This handler must never unlink outside the uploads '
                . 'root, whatever the caller sends.');
        }
    }

    /**
     * FAIL CLOSED ON A NULL STORE. A merchant with no store at all: `getCurrentStore()` answers
     * null, and a null store makes the derived prefix the uploads ROOT.
     */
    #[Test]
    public function test_a_merchant_with_no_store_is_refused(): void
    {
        $storeless = $this->makeMerchant();
        $this->grantPermission($storeless, 'Manage Store Setting');

        [, $storeB] = $this->makeTenant();

        $victim = $this->probeFile($storeB->id, 'ws4-nullstore-' . bin2hex(random_bytes(4)) . '.png');
        $absolute = base_path($victim);

        $response = $this->postDelete('user', $storeless, $victim);

        $this->assertSame(403, $response->getStatusCode(),
            'a merchant with no resolvable store was not refused with 403. A null store must fail '
            . 'closed: it makes the principal-derived prefix `uploads/`, which is the whole root.');

        $this->assertFileExists($absolute, 'a storeless merchant deleted another store\'s file.');
    }

    /**
     * THE TWO DEGENERATE CASES TOGETHER, which is the only combination the null-store check
     * actually covers -- and I only found that out by mutating it.
     *
     * `test_a_merchant_with_no_store_is_refused` above passes WITHOUT the null-store limb: with a
     * null store the derived prefix collapses to `uploads/`, which admits that case's
     * `uploads/<B>/file.png`, and `StoredUpload`'s tenant rule then refuses it because B's segment
     * IS digits. So on that path layer 3 is redundant and the mutation that removes it failed
     * nothing.
     *
     * AND THE OBVIOUS PROBE FOR IT DOES NOT WORK EITHER, which took two mutations to find out.
     * `filter_var(null, FILTER_VALIDATE_INT)` is `false`, `false` concatenates as `''`, and the
     * prefix is built as `'uploads/' . $storeId . '/'` -- so a null store collapses it to
     * `uploads//`, with a DOUBLE slash, which `str_starts_with` then matches against nothing. A
     * plain `uploads/<file>` is refused by layer 4, not layer 3. (My first version of this
     * docblock said the prefix collapses to `uploads/`; it does not, and that one missing trailing
     * slash is the whole difference.)
     *
     * SO THE PROBE SENDS THE DOUBLE SLASH, which is the one shape that reaches past layer 4 with a
     * null store: `uploads//<file>` starts with `uploads//`, and `realpath()` collapses the double
     * slash so `StoredUpload` resolves it to a real file whose first segment is not digits -- the
     * tenant rule is skipped and the unlink goes through. Fail-closed on a store that is not a
     * positive integer is the only thing standing in that combination, which is P1-SEC-71's own
     * shape: a write landing in a folder no store's id is part of. Mutation-verified: removing the
     * null-store limb fails THIS case and no other.
     */
    #[Test]
    public function test_a_storeless_merchant_cannot_delete_a_root_level_file(): void
    {
        $storeless = $this->makeMerchant();
        $this->grantPermission($storeless, 'Manage Store Setting');

        $name = 'ws4-nullroot-' . bin2hex(random_bytes(4)) . '.png';
        $absolute = base_path('uploads/' . $name);
        file_put_contents($absolute, 'probe');
        $this->made[] = $absolute;

        /* The DOUBLE slash is deliberate -- see the docblock. */
        $response = $this->postDelete('user', $storeless, 'uploads//' . $name);

        $this->assertFileExists($absolute,
            'a merchant with NO resolvable store deleted a file sitting directly in the uploads '
            . 'root. Both degenerate halves line up on this one path: a null store collapses the '
            . 'principal-derived prefix to `uploads//` (double slash -- `false` concatenates as '
            . 'the empty string), which the posted `uploads//<name>` DOES match, and then '
            . 'StoredUpload\'s tenant rule is skipped because the resolved first segment is not '
            . 'digits. Fail-closed on a store that is not a positive integer is the only control '
            . 'left standing.');

        $this->assertSame(403, $response->getStatusCode(),
            'the request was not refused with 403.');
    }

    /**
     * THE DEGENERATE STORED DATA, and the ONLY thing the principal-derived prefix catches that
     * `StoredUpload` does not.
     *
     * The uploader is `$dir = 'uploads/' . getCurrentStore()`, so a file uploaded while the store
     * was null sits at `uploads/<file>` with NO numeric segment. `StoredUpload::resolve()` reads
     * the tenant segment only when the first segment is digits (`ctype_digit($first)`), so for such
     * a path its tenant rule is SKIPPED and only "inside the uploads root" remains -- which every
     * merchant satisfies. The prefix layer is what refuses it, and this case is the reason that
     * layer is not redundant. Added after noticing that dropping the prefix check failed none of
     * the other cases.
     */
    #[Test]
    public function test_a_file_in_the_uploads_root_with_no_store_segment_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Store Setting');

        $dir = base_path('uploads');
        $name = 'ws4-rootlevel-' . bin2hex(random_bytes(4)) . '.png';
        $absolute = $dir . '/' . $name;
        file_put_contents($absolute, 'probe');
        $this->made[] = $absolute;

        $this->postDelete('user', $merchant, 'uploads/' . $name);

        $this->assertFileExists($absolute,
            'a file at `uploads/<name>` -- no store segment, which is what the uploader produces '
            . 'when getCurrentStore() is null -- was deleted by a merchant it does not belong to. '
            . 'StoredUpload\'s tenant rule cannot see such a path (its first segment is not '
            . 'digits), so the principal-derived prefix is the only thing that refuses it.');
    }

    /**
     * THE PERMISSION LAYER, which no other case here exercises: a merchant with a store, acting on
     * their OWN folder, but without `Manage Store Setting`.
     */
    #[Test]
    public function test_a_merchant_without_the_setting_permission_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        /* deliberately NOT granted 'Manage Store Setting' */

        $relative = $this->probeFile($store->id, 'ws4-noperm-' . bin2hex(random_bytes(4)) . '.png');
        $absolute = base_path($relative);

        $response = $this->postDelete('user', $merchant, $relative);

        $this->assertSame(403, $response->getStatusCode(),
            'a merchant without `Manage Store Setting` was not refused with 403. This is the '
            . 'permission this controller\'s own index() gates on.');

        $this->assertFileExists($absolute,
            'a merchant without `Manage Store Setting` deleted a file anyway, so the permission '
            . 'limb runs after the unlink or not at all.');
    }

    /**
     * THE PRINCIPAL-CLASS SHAPE, on a handler where it has teeth: `getCurrentStore()` returns the
     * literal 1 for anything typed `super admin`, and a `Customer` can carry that type.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = Customer::create([
            'name' => 'Impostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'type' => 'super admin',
        ]);

        $victim = $this->probeFile(1, 'ws4-store1-' . bin2hex(random_bytes(4)) . '.png');
        $absolute = base_path($victim);

        $this->postDelete('customer', $customer, $victim);

        $this->assertFileExists($absolute,
            'a Customer row typed `super admin` deleted a file in store 1\'s folder. '
            . '`getCurrentStore()` hands that literal 1 to anything with that type word, so the '
            . 'handler must establish that the principal is a `User` BEFORE it asks which store '
            . 'it is acting for.');
    }
}
