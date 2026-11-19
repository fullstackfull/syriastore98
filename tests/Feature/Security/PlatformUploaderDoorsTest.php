<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * P1-SEC-73 -- THE DOORS: WHO CAN REACH THE PLATFORM UPLOADER, AND DO THEY STILL GET THROUGH WITH A REAL FILE.
 * =============================================================================================================
 *
 * PlatformUploaderTypeTest drives the four functions, which is where the fix is. This file drives the REAL ROUTES of the three doors
 * the mapping measured as the widest, because a fix in a function proves nothing about whether a route reaches it, and because the
 * policy REFUSES a name it does not recognise -- so every door below also stores a legitimate file, or "refused" would be a
 * statement about a route that refuses everything.
 *
 *   1. `POST /app-setting` -- seven six-argument calls, behind `auth` alone: no permission is asked, so a merchant's staff member with
 *      NO permissions reached an uploader that ran no validator at all.
 *   2. `POST /{slug}/process-order` with `payment_type=bank_transfer` -- the receipt at a GUEST checkout: no account, no throttle, a
 *      six-argument call, stored under `<client name>_<time>` in `uploads/receipt/`, shared by every store.
 *   3. `POST /{slug}/store-ticket` -- a storefront customer's support-ticket attachment, stored under the client's own file name.
 *
 * Every case uses real bytes on disk. The `theme` disk is faked, so nothing here can reach the repository's `uploads/`.
 */
class PlatformUploaderDoorsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('theme');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function upload(string $clientName, string $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ssup');
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG);
    }

    /** @return list<string> what the faked `theme` disk holds */
    private function stored(): array
    {
        return Storage::disk('theme')->allFiles();
    }

    // ===========================================================================================================================
    // 3. a storefront customer's support-ticket attachment
    // ===========================================================================================================================

    private function aCustomerOf($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Ticket', 'last_name' => 'Shopper',
            'email' => 'ticket-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '0900' . random_int(100000, 999999), 'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function postTicket($store, Customer $customer, array $attachments)
    {
        return $this->actingAs($customer, 'customers')->post('/' . $store->slug . '/store-ticket', [
            'title' => 'A problem', 'description' => 'It does not work', 'attachments' => $attachments,
        ]);
    }

    public function test_a_customers_ticket_attachment_is_stored_when_it_is_an_image_and_not_when_it_is_a_page(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);

        $this->postTicket($store, $customer, [$this->upload('screenshot.png', $this->png())]);

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: a legitimate attachment reaches the uploader and is stored. Files: ' . json_encode($stored));
        $this->assertStringEndsWith('/tickets/screenshot.png', $stored[0]);

        Storage::fake('theme');
        $this->postTicket($store, $customer, [$this->upload('page.html', $this->png()), $this->upload('shell.php.png', $this->png())]);

        $this->assertSame([], $this->stored(), 'A customer stored an attachment named page.html or shell.php.png through the ticket form: ' . json_encode($this->stored()));
    }

    /** The shopper's list has no SVG: an SVG is a document that carries script on the store's own origin, and the merchant opens the ticket. */
    public function test_a_customers_ticket_attachment_may_not_be_an_svg(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);

        $this->postTicket($store, $customer, [$this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]);

        $this->assertSame([], $this->stored(), 'A customer stored an SVG through the ticket form: ' . json_encode($this->stored()));
    }

    // ===========================================================================================================================
    // 1. the Store Setting screen: seven six-argument calls behind `auth` and nothing else
    // ===========================================================================================================================

    private function postAppSetting($user, $file)
    {
        return $this->actingAs($user)->post('/app-setting', [
            'app_setting_tab' => 'pills-home-tab',
            'array' => [
                0 => [
                    'section_slug' => 'logo_section', 'array_type' => 'inner-list', 'loop_number' => 1,
                    'inner-list' => [
                        0 => ['field_slug' => 'logo', 'field_type' => 'photo upload', 'field_default_text' => $file],
                    ],
                ],
            ],
        ]);
    }

    /**
     * A MERCHANT'S STAFF MEMBER WITH NO PERMISSION AT ALL reaches the handler (it asks for none) -- so this is the reach, and the
     * owner is the control: the same request with a real image from the owner must still store it.
     */
    public function test_the_store_setting_screen_stores_an_image_and_refuses_a_page_even_for_staff_with_no_permission(): void
    {
        [$merchant] = $this->tenant();
        $staff = $this->makeStaff($merchant);

        $this->postAppSetting($merchant, $this->upload('logo.png', $this->png()));

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: the owner\'s legitimate logo reaches the uploader and is stored. Files: ' . json_encode($stored));
        $this->assertStringEndsWith('_logo.png', $stored[0]);

        Storage::fake('theme');
        $this->postAppSetting($staff, $this->upload('page.html', $this->png()));
        $this->assertSame([], $this->stored(), 'A staff member with no permission stored page.html through POST /app-setting: ' . json_encode($this->stored()));

        $this->postAppSetting($staff, $this->upload('shell.php', "<?php echo 'ss-probe'; ?>\n"));
        $this->assertSame([], $this->stored(), 'A staff member with no permission stored shell.php through POST /app-setting: ' . json_encode($this->stored()));
    }

    // ===========================================================================================================================
    // 2. the receipt at a GUEST checkout: no account, no throttle, a six-argument call
    // ===========================================================================================================================

    /** A store with one product, a shipping row and a GUEST basket keyed on the cart cookie. @return array{0: \App\Models\Store, 1: int, 2: string} */
    private function guestBasket(): array
    {
        [$merchant, $store] = $this->tenant();

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . $this->uniqueFixtureRef(), 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::forceCreate([
            'name' => 'sec73-' . $this->uniqueFixtureRef(), 'slug' => 'sec73-' . $this->uniqueFixtureRef(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $cookie = 'sec73-guest-' . $this->uniqueFixtureRef();
        DB::table('carts')->insert([
            'customer_id' => 0, 'cookie_session_id' => $cookie, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 10.00, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Cart)->flushCache();

        return [$store, $shippingId, $cookie];
    }

    private function guestBankTransfer($store, int $shippingId, string $cookie, UploadedFile $receipt)
    {
        $billing = [
            'firstname' => 'Guest', 'lastname' => 'Shopper', 'email' => 'guest-sec73@example.test',
            'billing_user_telephone' => '555111333', 'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111', 'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];

        return $this->withCookie('cart', $cookie)->post('/' . $store->slug . '/process-order', [
            'payment_type' => 'bank_transfer', 'delivery_id' => $shippingId, 'method_id' => $shippingId,
            'billing_info' => $billing, 'payment_receipt' => $receipt,
        ] + $billing);
    }

    /**
     * THE RECEIPT IS STORED AT THE TOP OF `getProductStatus()`, BEFORE ITS OWN REFUSALS -- so a receipt can be stored by a request that
     * goes on to be refused, and this door needs no account. A real receipt is the premise and the control: the checkout still writes
     * its order with the receipt on disk, under a name that ends in its extension.
     */
    public function test_a_guest_checkouts_receipt_is_stored_when_it_is_an_image_and_not_when_it_is_a_page(): void
    {
        [$store, $shippingId, $cookie] = $this->guestBasket();

        $response = $this->guestBankTransfer($store, $shippingId, $cookie, $this->upload('transfer receipt (1).png', $this->png()));

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: a legitimate receipt reaches the uploader and is stored. Files: ' . json_encode($stored) . ' Error: ' . json_encode(session('error')));
        $this->assertMatchesRegularExpression('#^uploads/receipt/transfer_receipt_1_\d{10}\.png$#', $stored[0], 'The receipt\'s stored name ends in its extension, and nothing of the client\'s punctuation survives into it.');
        $this->assertSame(1, (int) DB::table('orders')->where('store_id', $store->id)->count(), 'Premise: the checkout wrote its order. Error: ' . json_encode(session('error')));

        foreach (['page.html', 'shell.php.png', 'logo.svg', 'photo.pht'] as $name) {
            Storage::fake('theme');
            $bytes = $name === 'logo.svg' ? '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' : $this->png();

            $this->guestBankTransfer($store, $shippingId, $cookie, $this->upload($name, $bytes));

            $this->assertSame([], $this->stored(), "A visitor with no account stored a receipt named {$name}: " . json_encode($this->stored()));
        }
    }

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'sec73-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        return [$merchant, $store];
    }
}
