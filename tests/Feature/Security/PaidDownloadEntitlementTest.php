<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\DownloadGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * F217 — A PAID DOWNLOAD IS AUTHORISED BY AN ORDER, NOT BY KNOWING A URL.
 * =================================================================================================
 *
 * `AccountProfileController::downloadable_prodcut()` checked entitlement correctly — `shopper()`
 * then `ownedOrder()` — and then **emailed `get_file($path)`**, a URL under `public/uploads`, which
 * is a symlink to `../uploads`, so the web server served it directly. The authorization gated *who
 * may request the mail*; the file itself was public, behind
 * `rand(10, 100) . '_' . time() . '_' . $originalName` — 91 prefixes × a one-second timestamp × a
 * filename the product page often reveals.
 *
 * These cases drive the new route with real requests. They assert what the owner's decision names:
 * ownership or entitlement; the registered buyer AND the guest; a guessable link is never
 * authorisation; `Content-Disposition` and `nosniff`; no exposure across customers or stores.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT ASSERT, because it is not true yet: that the OLD public URL
 * has stopped working. Paid downloadables and ordinary product images are written to the **same
 * directory** by the same `Utility::upload_file()` call, so no path rule denies one without denying
 * the other — and the owner requires public media to keep working. The last case in this file
 * **pins that exposure** rather than papering over it, so the day it is contained the test fails and
 * somebody updates the record.
 */
final class PaidDownloadEntitlementTest extends SecurityTestCase
{
    use RefreshDatabase;

    private string $relativePath = '';

    /** Set only when THIS class created `public/uploads`, so teardown never removes an operator's. */
    private bool $createdPublicUploadsLink = false;

    protected function tearDown(): void
    {
        if ($this->relativePath !== '' && is_file(base_path($this->relativePath))) {
            @unlink(base_path($this->relativePath));
        }

        if ($this->createdPublicUploadsLink && is_link(public_path('uploads'))) {
            @unlink(public_path('uploads'));
            $this->createdPublicUploadsLink = false;
        }

        parent::tearDown();
    }

    /**
     * F238 — MAKE THE PRECONDITION, RATHER THAN DEPENDING ON SOMEBODY HAVING MADE IT.
     *
     * `public/uploads` is a SYMLINK to `../uploads`, it is gitignored
     * (`.gitignore:8:/public/uploads`), and it is created by an operator or a deploy step. So a
     * clean `git clone` does not have it — and the case below, which asserts a paid file is
     * reachable THROUGH it, failed in CI for that reason while passing on any machine where
     * somebody had run the deploy step.
     *
     * THAT IS WORSE THAN IT LOOKS, AND IT IS WHY THIS HELPER EXISTS RATHER THAN A SKIP. The case
     * is a characterization of a residual exposure: its failure is supposed to mean *"the
     * containment landed, come and update F217."* In CI it was failing because the SYMLINK was
     * absent, which means the suite has been reporting "contained" in the one environment that
     * runs it on every push, while nothing was contained. A test whose verdict depends on who
     * last ran a deploy script is not a characterization of anything.
     *
     * WHAT THE CASE STILL PROVES once the link is deterministic -- because "the test makes its own
     * precondition" can be a way to make a tautology, and it is worth saying why this is not one.
     * The claim is that **a paid downloadable is written into the directory tree that
     * `public/uploads` exposes**. If containment landed -- paid files written somewhere not under
     * `uploads/`, which is what F217's remedy would do -- then `$this->relativePath` would no
     * longer sit under `uploads/`, the derived public view would not exist, and the case would fail
     * exactly as designed. The symlink is the environment; the path is the claim.
     *
     * It creates the link ONLY if nothing is there, records that it did, and removes it in teardown
     * only in that case -- so an operator's own symlink, or a real directory, is never touched.
     */
    private function requirePublicUploadsView(): void
    {
        $link = public_path('uploads');

        // ALREADY THE PRODUCTION SHAPE. Nothing to do, and nothing of the operator's to touch.
        if (is_link($link)) {
            return;
        }

        /*
         * A REAL DIRECTORY, NOT A SYMLINK -- AND THIS BRANCH IS THE WHOLE REASON THE FIRST VERSION
         * OF THIS HELPER WAS NOT ENOUGH.
         *
         * It tested `file_exists($link) || is_link($link)` and returned early on either. A real
         * directory satisfies `file_exists()`, so the symlink was never created and the case failed
         * anyway -- on its own "the containment landed" message, which is the misleading failure
         * this helper exists to prevent.
         *
         * WHERE THE DIRECTORY COMES FROM, measured rather than guessed. A sibling in the SAME
         * suite creates it: AdminApiSettingsSecretsTest::test_the_store_image_upload_lands_in_the_
         * stores_own_folder drives `updateStoreOwnerInfo` with a fake image, and
         * Api\DashboardController:8836 does `$file->move(public_path('uploads/' . $storeId), ...)`.
         * Symfony's File::getTargetFile() creates the destination RECURSIVELY
         * (`!@mkdir($directory, 0o777, true)`), so it creates `public/uploads/` on the way to
         * `public/uploads/<id>/`, and that test's `finally` removes only the inner one. It runs
         * BEFORE this class -- measured from the JUnit log of the last full run, not assumed.
         * That sibling now cleans up after itself too, so this branch is a second line of defence
         * rather than the only one.
         *
         * An EMPTY one is that leftover: remove it and establish the real shape. A NON-EMPTY one is
         * somebody's actual upload directory and is not touched -- in that case the environment is
         * genuinely not the production shape, because a paid file written to the repo-root
         * `uploads/<id>/` is not underneath it, and saying so is better than quietly reporting a
         * containment.
         */
        if (is_dir($link)) {
            $entries = array_diff((array) @scandir($link) ?: [], ['.', '..']);

            if ($entries !== []) {
                $this->fail(
                    '`public/uploads` is a real, NON-EMPTY directory rather than a symlink into the '
                    . 'uploads root, so this environment is not the shape F217 is about and this '
                    . 'case cannot characterize it. In production `public/uploads` is a symlink, '
                    . 'which is why the web server serves a paid file before PHP is involved. '
                    . 'Contents: ' . implode(', ', array_slice($entries, 0, 5))
                );
            }

            if (! @rmdir($link)) {
                $this->fail('`public/uploads` is an empty real directory that could not be removed.');
            }
        }

        if (! is_dir(dirname($link))) {
            $this->fail('public/ does not exist, so the public view cannot be established at all.');
        }

        if (! @symlink('../uploads', $link)) {
            $this->fail(
                'Could not create the `public/uploads` symlink, so this characterization cannot '
                . 'run. It is the operator artefact F217 is about: `public/uploads -> ../uploads`, '
                . 'which is why the web server serves a paid file before PHP is involved. If '
                . 'symlinks are unavailable on this platform, say so here rather than letting the '
                . 'case report a containment that has not happened -- that is F238.'
            );
        }

        $this->createdPublicUploadsLink = true;
    }

    /** The buyer whose order it is gets the bytes, and the response is an attachment. */
    public function test_the_buying_customer_can_download_and_the_response_is_a_hardened_attachment(): void
    {
        [$store, $customer, $order] = $this->paidOrder();

        $response = $this->actingAs($customer, 'customers')
            ->get('/' . $store->slug . '/paid-download/' . $order->id);

        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'),
            'the response does not forbid content sniffing, so a mislabelled file can be '
            . 're-interpreted by the browser (F217)');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'),
            'the file is not served as an attachment, so a type the browser renders inline would '
            . 'execute on the store\'s own origin');
        /* THE PREMISE, and the first version of it asked the wrong object: `streamedContent()`
           throws for a `BinaryFileResponse`, which is what `response()->download()` returns — it
           sends a file handle, not a streamed closure. Ask the response for its file instead. */
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse,
            'premise: the route did not return a file response, so the headers above may be '
            . 'describing an error page rather than a download');
        $this->assertSame(
            'ss-f217 payload',
            file_get_contents($response->baseResponse->getFile()->getPathname()),
            'the route served a different file than the order names'
        );
    }

    /** THE DEFECT: a stranger with the URL gets nothing. */
    public function test_an_anonymous_caller_with_the_url_is_refused(): void
    {
        [$store, , $order] = $this->paidOrder();

        $this->get('/' . $store->slug . '/paid-download/' . $order->id)
            ->assertNotFound();
    }

    /** And neither does a DIFFERENT signed-in customer of the same store. */
    public function test_another_customer_of_the_same_store_is_refused(): void
    {
        [$store, , $order] = $this->paidOrder();

        $stranger = $this->customerIn($store);

        $this->actingAs($stranger, 'customers')
            ->get('/' . $store->slug . '/paid-download/' . $order->id)
            ->assertNotFound();
    }

    /** A customer of ANOTHER store cannot reach it either, even naming the right order id. */
    public function test_a_customer_of_another_store_is_refused(): void
    {
        [$store, , $order] = $this->paidOrder();
        [, $otherStore] = $this->makeTenant();
        $outsider = $this->customerIn($otherStore);

        $this->actingAs($outsider, 'customers')
            ->get('/' . $otherStore->slug . '/paid-download/' . $order->id)
            ->assertNotFound();
    }

    /** THE GUEST, which is why the grant exists: no account, but a grant mailed to the order. */
    public function test_a_guest_order_downloads_with_its_grant_and_not_without_it(): void
    {
        [$store, , $order] = $this->paidOrder(['customer_id' => 0, 'is_guest' => 1]);

        $this->get('/' . $store->slug . '/paid-download/' . $order->id)
            ->assertNotFound();

        $grant = DownloadGrant::issue($order, $this->relativePath, 0);

        $this->get('/' . $store->slug . '/paid-download/' . $order->id . '?' . DownloadGrant::FIELD . '=' . $grant)
            ->assertOk();
    }

    /** A forged or stale grant is not a grant. */
    public function test_a_forged_grant_is_refused(): void
    {
        [$store, , $order] = $this->paidOrder(['customer_id' => 0, 'is_guest' => 1]);

        foreach ([str_repeat('a', 40), '1', 'not-hex', str_repeat('f', 39)] as $forged) {
            $this->get('/' . $store->slug . '/paid-download/' . $order->id . '?' . DownloadGrant::FIELD . '=' . $forged)
                ->assertNotFound();
        }
    }

    /** A grant for one order does not open another order's file. */
    public function test_a_grant_is_bound_to_its_own_order(): void
    {
        [$store, , $orderA] = $this->paidOrder(['customer_id' => 0, 'is_guest' => 1]);
        $pathA = $this->relativePath;
        [, , $orderB] = $this->paidOrder(['customer_id' => 0, 'is_guest' => 1], $store);

        $grantForA = DownloadGrant::issue($orderA, $pathA, 0);

        $this->get('/' . $store->slug . '/paid-download/' . $orderB->id . '?' . DownloadGrant::FIELD . '=' . $grantForA)
            ->assertNotFound();
    }

    /** A stored path that escapes the uploads root is refused even for the entitled buyer. */
    public function test_a_stored_path_that_escapes_the_uploads_root_is_refused(): void
    {
        [$store, $customer, $order] = $this->paidOrder();

        DB::table('products')->where('store_id', $store->id)
            ->update(['downloadable_product' => 'uploads/../.env']);

        $this->actingAs($customer, 'customers')
            ->get('/' . $store->slug . '/paid-download/' . $order->id)
            ->assertNotFound();
    }

    /** An order with nothing downloadable is a 404, not an error page. */
    public function test_an_order_with_nothing_downloadable_is_refused(): void
    {
        [$store, $customer, $order] = $this->paidOrder();

        DB::table('products')->where('store_id', $store->id)
            ->update(['downloadable_product' => null]);

        $this->actingAs($customer, 'customers')
            ->get('/' . $store->slug . '/paid-download/' . $order->id)
            ->assertNotFound();
    }

    /**
     * THE RESIDUAL, PINNED RATHER THAN HIDDEN.
     *
     * The old public URL still resolves, because paid downloadables and ordinary product images are
     * written to the SAME directory — `uploads/{store_id}/` — by the same `Utility::upload_file()`
     * call. No path rule denies one without denying the other, and the owner requires authorised
     * public media to keep working. Containing the existing files means MOVING them and rewriting
     * their database paths, which is a production data write and is forbidden.
     *
     * So this case asserts the exposure EXISTS, which is an odd-looking test and a deliberate one:
     * the owner's decision says *"adding a new route alone does not close F217 if the old public
     * link still works."* The day the containment lands, this test fails, and whoever lands it has
     * to come here and say so. That is the opposite of a test that quietly passes while a claim
     * rots.
     */
    public function test_the_old_public_path_is_still_reachable_and_that_is_why_f217_is_not_closed(): void
    {
        [$store, , $order] = $this->paidOrder();

        $this->assertFileExists(base_path($this->relativePath),
            'premise: the fixture really wrote a file under the uploads root');

        // F238: establish the operator artefact rather than depending on it -- see the helper.
        $this->requirePublicUploadsView();

        $this->assertTrue(
            is_link(public_path('uploads')),
            'public/uploads is not a SYMLINK after requirePublicUploadsView(). A real directory is '
            . 'not the shape F217 is about: a paid file written to the repo-root `uploads/<id>/` is '
            . 'not underneath one. The first version of this premise accepted `is_dir()` too, and '
            . 'that is exactly how the leftover directory a sibling test creates slipped past it.'
        );

        /*
         * Asserted as a FILESYSTEM fact rather than by driving an HTTP request, because PHPUnit's
         * test client routes through Laravel and never reaches the web server that serves static
         * files. What makes the file public is that `public/uploads` is a symlink into the uploads
         * root, so a request for `/uploads/...` is answered by the web server before PHP is
         * involved. That is exactly why the application could not defend it, and why the remaining
         * step is an operator action rather than a patch.
         */
        $publicView = public_path('uploads') . substr($this->relativePath, strlen('uploads'));

        $this->assertFileExists($publicView,
            'the paid file is no longer reachable through public/uploads. If that is the '
            . 'containment landing, F217 can move to closed and this case should be replaced by '
            . 'its inverse.');
    }

    // ============================================================================== the fixture

    /** @return array{0: \App\Models\Store, 1: Customer, 2: Order} */
    // =============================================================================================
    // SEC-INTAKE #188 -- THE ADOPTED GUEST ORDER, which every predicate on this path misses
    // =============================================================================================

    /**
     * `PaidDownloadController::entitled()`'s CUSTOMER limb asks `orders.customer_id` and never
     * `orders.is_guest`, and its own prose at `:208-209` states the premise that makes that look
     * safe: "For a guest order the stored customer id is 0."
     *
     * It is not 0 for every guest order. `app/Services/PaymentService.php:3019-3033`: a guest
     * checkout whose billing e-mail matches an existing customer OF THIS STORE is written with
     * that customer's REAL id and `is_guest = 1`, because nobody was signed in. So the matched
     * customer -- who never placed the order and never proved they own that e-mail address --
     * satisfies the customer limb and is handed somebody else's paid file.
     *
     * This is the money-adjacent limb of the intake: not a disclosure of another ACCOUNT's data,
     * but delivery of purchased digital goods to the wrong person.
     */
    public function test_an_adopted_guest_order_is_not_downloadable_by_the_matched_customer(): void
    {
        [$store, $customer, $order] = $this->paidOrder(['is_guest' => 1]);

        // PREMISES. Without these the test could be measuring an ordinary guest order, which is
        // refused by a different limb and would make this pass for the wrong reason.
        $row = DB::table('orders')->where('id', $order->id)->first(['customer_id', 'is_guest']);
        $this->assertSame(1, (int) $row->is_guest,
            'premise failed: the fixture is not a guest order, so the predicate under test is '
            . 'not the one doing the refusing.');
        $this->assertSame((int) $customer->id, (int) $row->customer_id,
            'premise failed: the fixture is an ORDINARY guest order (customer_id 0), which the '
            . '`customer_id` limb refuses on its own. The shape this test exists for is a REAL '
            . 'customer id with is_guest = 1.');

        $this->actingAs($customer, 'customers')
            ->get('/' . $store->slug . '/paid-download/' . $order->id)
            ->assertNotFound();
    }

    /**
     * THE ORACLE FOR THE LIMB THAT MUST NOT CHANGE: the grant path still admits.
     *
     * WHAT THIS PINS, STATED EXACTLY, because the obvious reading of it is wrong. It pins the
     * CONTROLLER'S LIMB, not a journey a guest can take. It mints the grant by calling
     * `DownloadGrant::issue()` directly with `customer_id = 0`, and NO reachable path produces
     * that for an adopted order -- `AccountProfileController:1109` passes the order's non-zero
     * id. `PaidDownloadLinkInPagesTest::test_the_guest_limb_of_the_controller_is_now_recorded_as_unreachable`
     * made the same correction for its sibling and is the fuller account.
     *
     * So the claim is narrow and worth having: the fix above must not make the grant limb refuse
     * a guest order, because that limb is the mechanism the guest journey would use when the
     * owner's D6 is built. It is not evidence that a guest can download anything today.
     */
    public function test_an_adopted_guest_order_still_downloads_with_its_grant(): void
    {
        [$store, , $order] = $this->paidOrder(['is_guest' => 1]);

        $grant = DownloadGrant::issue($order, $this->relativePath, 0);

        $this->get('/' . $store->slug . '/paid-download/' . $order->id . '?' . DownloadGrant::FIELD . '=' . $grant)
            ->assertOk();
    }

    /**
     * And no link is MINTED for it either. `paid_download_url()` (`app/Helper/helper.php:410-411`)
     * carries the same `customer_id`-only gate, and minting is not a read: it calls
     * `DownloadGrant::issue()`, which WRITES a token into the cache
     * (`app/Services/DownloadGrant.php:145-158`). So the page that renders the link hands out a
     * capability on a GET.
     */
    public function test_no_download_link_is_minted_for_an_adopted_guest_order(): void
    {
        [, $customer, $order] = $this->paidOrder(['is_guest' => 1]);

        $this->actingAs($customer, 'customers');

        $this->assertNull(
            paid_download_url($order->fresh(), $this->relativePath),
            'a paid-download link was minted for an order the signed-in customer did not place, '
            . 'and minting it also wrote a DownloadGrant into the cache.'
        );
    }

    /** The same helper must still mint for an order the customer really did place. */
    public function test_a_link_is_still_minted_for_the_customers_own_order(): void
    {
        [, $customer, $order] = $this->paidOrder();

        $this->actingAs($customer, 'customers');

        $this->assertNotNull(
            paid_download_url($order->fresh(), $this->relativePath),
            'the ordinary buyer stopped getting a download link, so the refusal above proves '
            . 'nothing.'
        );
    }

    private function paidOrder(array $orderOverrides = [], $existingStore = null): array
    {
        if ($existingStore === null) {
            [$merchant, $store] = $this->makeTenant();
        } else {
            $store = $existingStore;
            $merchant = \App\Models\User::find($store->created_by);
        }

        $customer = $this->customerIn($store);

        $dir = 'uploads/' . $store->id;
        @mkdir(base_path($dir), 0777, true);
        $this->relativePath = $dir . '/ss-f217-' . uniqid() . '.zip';
        file_put_contents(base_path($this->relativePath), 'ss-f217 payload');

        $productId = DB::table('products')->insertGetId([
            'name' => 'Paid download ' . uniqid(),
            'slug' => 'paid-' . uniqid(),
            'store_id' => $store->id,
            'created_by' => (int) $store->created_by,
            'track_stock' => 0,
            'downloadable_product' => $this->relativePath,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder($store, array_merge([
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'payment_status' => 'Paid',
            'product_json' => json_encode([['product_id' => $productId, 'variant_id' => 0, 'quantity' => 1]]),
        ], $orderOverrides));

        return [$store, $customer, $order];
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'F217', 'last_name' => 'Buyer',
            'email' => 'f217-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }
}
