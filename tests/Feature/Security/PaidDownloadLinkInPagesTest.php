<?php

namespace Tests\Feature\Security;

use App\Services\DownloadGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F217 — THE HALF THAT WAS MISSED: the MAIL was fixed and the PAGE was not.
 *
 * An independent audit of this phase found it, and it is a gap in my own F217 work rather than a
 * new discovery. `AccountProfileController::downloadable_prodcut()` was changed to mail a
 * grant-protected route, and that was treated as F217's first part. But **eight first-party
 * storefront blade sites still rendered `href="{{ get_file($x->downloadable_product) }}"`** on the
 * buyer's own order and refund pages, and `get_file()`'s local limb is `url($path)` — the raw
 * public URL. The UI offered the unprotected link beside the protected one.
 *
 * Those anchors are not decoration: they are the elements the download button's JS activates. And
 * the URL does not have to be clicked to leak — it is in the page's HTML, so view-source, an
 * extension, a shared devtools screenshot or anything that scrapes the page gets a link needing no
 * order, no session and no entitlement, because `public/uploads` is a symlink to `../uploads` and
 * `public/.htaccess` only routes to `index.php` when the requested file does NOT exist.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * AND THE FIX HAD TO BE NARROWER THAN "USE A GRANT EVERYWHERE", which is the finding inside the
 * finding.
 *
 * One of the four pages — `OrderController::orderDetails`, route `{storeSlug?}/order/{id}`, which
 * is NOT inside `EnsureCustomerAuthenticated` — authorises with `decrypt()` plus
 * `->where('store_id', $store->id)`. That is an ENCODING AND A STORE CHECK, not a customer check,
 * and CLAUDE.md records why the encoding is not a control: `APP_KEY` is in git history and
 * unrotated, so an encrypted id in a URL is forgeable.
 *
 * So minting a grant on that page would have **turned a pre-existing order-detail exposure into a
 * paid-file exposure** — and minted the capability myself rather than merely failing to prevent a
 * guess. `paid_download_url()` therefore requires the signed-in buyer, and returns null otherwise.
 * That is strictly safer than before in both directions: the entitled buyer gets a grant instead
 * of a public URL, and everyone else gets no link instead of a public URL.
 */
class PaidDownloadLinkInPagesTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The four storefront blades that rendered the unprotected link. */
    private const STOREFRONT_BLADES = [
        'resources/views/front_end/sections/order_view.blade.php',
        'resources/views/front_end/sections/order_detail.blade.php',
        'resources/views/front_end/pages/order-detail.blade.php',
        'resources/views/front_end/pages/refund_order.blade.php',
    ];

    // ───────────────────────────────────────────────── the ratchet on the views

    public function test_no_storefront_page_renders_a_raw_url_for_a_paid_downloadable(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/front_end/**/*.blade.php'), GLOB_BRACE) ?: [] as $file) {
            $this->collectOffenders($file, $offenders);
        }

        foreach (self::STOREFRONT_BLADES as $relative) {
            $this->collectOffenders(base_path($relative), $offenders);
        }

        $this->assertSame(
            [],
            array_unique($offenders),
            "A storefront page renders `get_file(...downloadable_product)` again — the raw public "
            . "URL to paid goods, in HTML, to whoever can open the page. Use "
            . "`paid_download_url(\$order, \$path)`, which issues a DownloadGrant for the signed-in "
            . "buyer and returns null for anyone else:\n  " . implode("\n  ", array_unique($offenders))
        );
    }

    private function collectOffenders(string $file, array &$offenders): void
    {
        if (! is_file($file)) {
            return;
        }

        foreach (file($file) ?: [] as $i => $line) {
            if (preg_match('/get_file\s*\(\s*\$[A-Za-z_]+->downloadable_product/', $line)) {
                $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . ($i + 1);
            }
        }
    }

    public function test_all_four_pages_actually_call_the_protected_helper(): void
    {
        // The other direction: the ratchet above also passes if the links were simply deleted,
        // which would break the buyer's download rather than protect it.
        foreach (self::STOREFRONT_BLADES as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            $this->assertStringContainsString(
                'paid_download_url($order,',
                $source,
                $relative . ' no longer offers a download link at all. Removing the buyer\'s own '
                . 'download is not the fix; routing it through the entitlement check is.'
            );
        }

        // The measured census, so a new unprotected site is noticed rather than silently added.
        $calls = 0;

        foreach (self::STOREFRONT_BLADES as $relative) {
            $calls += preg_match_all(
                '/paid_download_url\(\$order,/',
                (string) file_get_contents(base_path($relative)),
                $m
            );
        }

        $this->assertSame(8, $calls, 'the eight sites the audit found, all converted');
    }

    // ────────────────────────────────────────── the helper's own behaviour

    public function test_the_helper_issues_a_working_grant_for_the_signed_in_buyer(): void
    {
        [$store, $customer, $order, $path] = $this->paidOrderFixture();

        $this->actingAs($customer, 'customers');

        $url = paid_download_url($order, $path);

        $this->assertNotNull($url, 'the entitled buyer must get a link');
        $this->assertStringContainsString('/paid-download/', $url);
        $this->assertStringNotContainsString($path, $url, 'the stored path must not be disclosed');

        // And it genuinely works, rather than merely looking right.
        $this->get($url)->assertOk();
    }

    public function test_the_helper_refuses_a_viewer_who_is_not_the_buyer(): void
    {
        [$store, , $order, $path] = $this->paidOrderFixture();
        [, $otherCustomer] = $this->paidOrderFixture($store);

        $this->actingAs($otherCustomer, 'customers');

        $this->assertNull(
            paid_download_url($order, $path),
            'another customer of the same store must get no link — and must certainly not get a '
            . 'minted grant'
        );
    }

    public function test_the_helper_refuses_an_anonymous_viewer_rather_than_minting_a_grant(): void
    {
        /*
         * THE CASE THE WHOLE PREDICATE EXISTS FOR. `{storeSlug?}/order/{id}` is reachable without
         * a customer session, and its only checks are `decrypt()` (an encoding — APP_KEY is in git
         * history) and a store predicate. If this ever returns a URL, the helper is minting a
         * capability for a viewer who proved nothing.
         */
        [, , $order, $path] = $this->paidOrderFixture();

        $this->assertNull(
            paid_download_url($order, $path),
            'an anonymous viewer must get NO link. Returning a grant here would turn a '
            . 'pre-existing order-detail exposure (F221) into a paid-file exposure.'
        );
    }

    public function test_nothing_to_download_means_no_link_rather_than_a_broken_one(): void
    {
        [, $customer, $order] = $this->paidOrderFixture();

        $this->actingAs($customer, 'customers');

        $this->assertNull(paid_download_url($order, null));
        $this->assertNull(paid_download_url($order, ''));
        $this->assertNull(paid_download_url($order, '   '));
        $this->assertNull(paid_download_url(null, 'uploads/1/files/x.pdf'));
    }

    public function test_the_guest_limb_of_the_controller_is_now_recorded_as_unreachable(): void
    {
        /*
         * A CORRECTION TO MY OWN EARLIER TEST, recorded rather than quietly dropped.
         *
         * `PaidDownloadEntitlementTest::test_a_guest_order_downloads_with_its_grant_and_not_without_it`
         * calls `DownloadGrant::issue(..., 0)` DIRECTLY. That proves the controller honours a guest
         * grant; it does NOT prove a guest can ever obtain one, and I presented it as the guest
         * journey working. The audit was right to call it out.
         *
         * Measured: `DownloadGrant::issue` has exactly two call sites, and NEITHER can serve an
         * unauthenticated guest — `AccountProfileController` is inside
         * `EnsureCustomerAuthenticated` and behind `ownedOrder()`'s `customer_id` predicate, and
         * `paid_download_url()` requires the signed-in buyer (above). So the controller's guest
         * limb is correct, defensive, and currently unreachable in production.
         *
         * It stays in the controller deliberately: the owner's D6 requires the guest to be
         * supported "under a safe purchase model", and the limb is what that model would use. What
         * is NOT claimed any more is that a guest purchase works end to end today.
         */
        $callSites = 0;

        foreach (['app', 'resources'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }

                $callSites += preg_match_all(
                    '/DownloadGrant::issue\(/',
                    (string) file_get_contents($entry->getPathname()),
                    $m
                );
            }
        }

        $this->assertSame(
            2,
            $callSites,
            'DownloadGrant::issue has gained or lost a call site. If a new one can serve an '
            . 'unauthenticated caller, the guest journey may now be genuinely reachable — update '
            . 'this test AND the controller\'s own docblock, which currently says the limb is '
            . 'defensive rather than exercised.'
        );
    }

    // ───────────────────────────────────────────────────────────── fixture

    /** @return array{0: \App\Models\Store, 1: \App\Models\Customer, 2: \App\Models\Order, 3: string} */
    private function paidOrderFixture($store = null): array
    {
        if ($store) {
            $merchant = \App\Models\User::where('type', 'admin')->first();
        } else {
            [$merchant, $store] = $this->makeTenant();
        }

        $customer = \App\Models\Customer::create([
            'name' => 'Buyer ' . uniqid(),
            'email' => 'buyer-' . uniqid() . '@example.test',
            'password' => bcrypt('secret'),
            'store_id' => $store->id,
            'type' => 'customer',
        ]);

        $relative = 'uploads/' . $store->id . '/files/ebook-' . uniqid() . '.pdf';
        $absolute = base_path($relative);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }

        file_put_contents($absolute, 'PAID CONTENT');

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Ebook', 'slug' => 'ebook-' . uniqid(), 'store_id' => $store->id,
            'downloadable_product' => $relative, 'product_type' => 'digital', 'track_stock' => 0,
            'created_by' => $merchant->id,   // products.created_by is NOT NULL
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $productId, 'name' => 'Ebook', 'qty' => 1, 'final_price' => 10,
            ]]),
        ]);

        return [$store, $customer, $order, $relative];
    }
}
