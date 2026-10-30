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

    protected function tearDown(): void
    {
        if ($this->relativePath !== '' && is_file(base_path($this->relativePath))) {
            @unlink(base_path($this->relativePath));
        }

        parent::tearDown();
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

        $this->assertDirectoryExists(public_path('uploads'),
            'public/uploads is absent, so this residual may already be contained — if that is '
            . 'deliberate, update F217 and delete this case');

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
