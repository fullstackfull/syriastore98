<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Services\PlatformUploadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-73 -- THE DOORS: WHO CAN REACH THE PLATFORM UPLOADER, AND DO THEY STILL GET THROUGH WITH A REAL FILE.
 * =============================================================================================================
 *
 * PlatformUploaderTypeTest drives the four functions, which is where the fix is. This file drives the REAL ROUTES of the doors
 * the mapping and the review measured as the widest, because a fix in a function proves nothing about whether a route reaches it, and
 * because the policy REFUSES a name it does not recognise -- so every door below also stores a legitimate file, or "refused" would be a
 * statement about a route that refuses everything. (The first form of the guest-checkout case placed one order and then reused its
 * basket for every refusal: the checkout answered "Cart is empty" before the uploader was reached, and the case could not fail.
 * Every refusal below is preceded by a basket, an order or a ticket of its own, and followed by a control that proves it was usable.)
 *
 *   1. `POST /app-setting` and `POST /product-page-setting` -- seven six-argument calls behind `auth` alone: no permission is asked, so a
 *      merchant's staff member with NO permissions reached an uploader that ran no validator at all.
 *   2. `POST /{slug}/process-order` with `payment_type=bank_transfer` -- the receipt at a GUEST checkout: no account, no throttle.
 *   3. `POST /{slug}/store-ticket`, `/{slug}/support-ticket/{id}` and `/{slug}/order-refund-request/{id}` -- a storefront customer's
 *      attachments. THE REFUND SCREEN LINKS THEM WITHOUT `download`, so what is stored there is OPENED by the merchant.
 *   4. `POST /plan-pay-with-bank` -- a merchant's payment slip, opened by the PLATFORM OPERATOR from a link.
 *   5. `POST api/admin/updateStoreOwnerInfo` -- the shipped app's invoice-logo and store-image upload, which never went through the
 *      uploader at all (`$file->move()` under the client's own name).
 *
 * AND WHO IS A SHOPPER. The first form of the policy asked whether a `web` user was signed in; self-registration is open, so any visitor
 * holds one with a single request, and a shopper holding both cookies was given the panel's list, SVG included, at doors 2 and 3. The
 * policy now asks the ROUTE (an `auth` middleware), and the cases below drive that with both cookies at the real doors.
 *
 * Every case uses real bytes on disk. The `theme` disk is faked, so nothing here can reach the repository's `uploads/`.
 */
class PlatformUploaderDoorsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';
    private const GIF = 'R0lGODdhAgACAIAAAMweHAAAACwAAAAAAgACAAACAoRRADs=';
    private const WEBP = 'UklGRjgAAABXRUJQVlA4ICwAAACQAQCdASoCAAIAAkA4JaACdLoAA5gA/vA4Q+6L7UVgB/7Kx/6Vj/0rH7lAAA==';
    private const BMP = 'Qk1CAAAAAAAAADYAAAAoAAAAAgAAAAIAAAABABgAAAAAAAwAAAAAAAAAAAAAAAAAAAAAAAAAHh7IHh7IAAAeHsgeHsgAAA==';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    private const JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5miiivIP0Q//2Q==';

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
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
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

    /** Forget what the faked disk holds, between the two halves of a case. */
    private function emptyTheDisk(): void
    {
        Storage::fake('theme');
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

    private function aCustomerOf($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Ticket', 'last_name' => 'Shopper',
            'email' => 'ticket-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '0900' . random_int(100000, 999999), 'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** Browser state as it really is: a storefront session AND a panel session, in two guards. A merchant of another tenant is the commonest `web` login. */
    private function holdingBothCookies(Customer $customer): void
    {
        [$stranger] = $this->tenant();

        $this->actingAs($stranger, 'web');
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');          // production's default guard: `actingAs(..., 'customers')` made it `customers`

        $this->assertTrue(auth('web')->check() && auth('customers')->check(), 'Premise: both guards hold a principal.');
    }

    // ===========================================================================================================================
    // 3. a storefront customer's attachments
    // ===========================================================================================================================

    private function postTicket($store, Customer $customer, array $attachments)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/store-ticket', [
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
        $this->assertMatchesRegularExpression('#/tickets/screenshot_\d{10}_[0-9a-f]{8}\.png$#', $stored[0], 'P1-SEC-71: the stored name is one the server built, not the client\'s own.');

        $this->emptyTheDisk();
        $this->postTicket($store, $customer, [$this->upload('page.html', $this->png())]);

        $this->assertSame([], $this->stored(), 'A customer stored an attachment named page.html through the ticket form: ' . json_encode($this->stored()));

        // P1-SEC-71: the stored name is the SERVER'S now, so a dot of the client's never reaches it -- `shell.php.png` is a PNG, and is stored as
        // `shell_php_<time>_<hex>.png` (the receipt's rule, for the same reason). Before, the policy refused the name; it no longer has to.
        $this->postTicket($store, $customer, [$this->upload('shell.php.png', $this->png())]);

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'A PNG named shell.php.png is stored under a harmless name. Files: ' . json_encode($stored));
        $this->assertMatchesRegularExpression('#/tickets/shell_php_\d{10}_[0-9a-f]{8}\.png$#', $stored[0]);
    }

    /** The shopper's list has no SVG: an SVG is a document that carries script on the store's own origin, and the merchant opens the ticket. */
    public function test_a_customers_ticket_attachment_may_not_be_an_svg(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);

        $this->postTicket($store, $customer, [$this->upload('logo.svg', self::SVG)]);

        $this->assertSame([], $this->stored(), 'A customer stored an SVG through the ticket form: ' . json_encode($this->stored()));
    }

    /**
     * THE FIRST FORM OF THE POLICY ASKED WHETHER A `web` USER WAS SIGNED IN, and registration is open: `POST /register` logs a new
     * merchant in, and the `web` and `customers` guards live under separate session keys, so a visitor holds both at once. Measured on
     * the real routes: customers alone refused the SVG, web + customers stored it -- in the VICTIM store's folder.
     */
    public function test_a_shopper_who_also_holds_a_panel_login_is_still_a_shopper_at_the_ticket_door(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);

        $this->holdingBothCookies($customer);
        $this->post('/' . $store->slug . '/store-ticket', ['title' => 'A problem', 'description' => 'It does not work', 'attachments' => [$this->upload('screenshot.png', $this->png())]]);
        $this->assertCount(1, $this->stored(), 'Premise: with both cookies the request reaches the uploader and a PNG is stored. Files: ' . json_encode($this->stored()));

        $this->emptyTheDisk();
        $this->post('/' . $store->slug . '/store-ticket', ['title' => 'A problem', 'description' => 'It does not work', 'attachments' => [$this->upload('logo.svg', self::SVG)]]);

        $this->assertSame([], $this->stored(), 'A shopper holding a panel login stored an SVG in the store\'s ticket folder: ' . json_encode($this->stored()));
    }

    /** THE DOOR WHERE THE MERCHANT OPENS THE FILE FROM A LINK (refund_details.blade.php prints `<a href target="_blank"><img>`, with no `download`). */
    public function test_a_shopper_who_also_holds_a_panel_login_is_still_a_shopper_at_the_refund_evidence_door(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $order = $this->makeOrder($store, [
            'product_order_id' => 'ORD-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_price' => 500, 'delivery_price' => 20, 'tax_price' => 20, 'coupon_price' => 0, 'final_price' => 540,
            'product_json' => json_encode([['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300], ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 2, 'final_price' => 200]]),
        ]);

        $request = fn (array $files) => $this->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id, 'product_refund_id' => [501], 'quantity' => [1, 0], 'return_price' => ['P', 'P'], 'product_sub_total' => 100,
            'refund_reason' => 'Other', 'custom_refund_reason' => 'x', 'attachments' => $files,
        ]);

        $this->holdingBothCookies($customer);

        $request([$this->upload('proof.png', $this->png())]);
        $this->assertCount(1, $this->stored(), 'Premise: the refund evidence door stores a PNG. Files: ' . json_encode($this->stored()) . ' Refunds: ' . DB::table('order_refunds')->count());

        $this->emptyTheDisk();
        DB::table('order_refunds')->delete();
        $request([$this->upload('proof.svg', self::SVG)]);

        $this->assertSame([], $this->stored(), 'A shopper holding a panel login stored an SVG as refund evidence, which the merchant opens from a link: ' . json_encode($this->stored()));
    }

    /**
     * WHAT THE POLICY CALLS A PANEL REQUEST, by the route rather than by the cookies: a `web` user, on a route that demands one.
     * (`custom.auth` is where a merchant's Sanctum token lands in the `web` guard, so it counts; a route of `auth:customers` or no auth
     * does not, whoever else is signed in.)
     */
    public function test_a_panel_request_is_one_whose_route_demands_a_panel_user(): void
    {
        [$merchant] = $this->tenant();
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);

        $probe = fn () => response()->json(['panel' => PlatformUploadPolicy::panelSession()]);
        Route::middleware('web')->post('/_sec73/public', $probe);
        Route::middleware(['web', 'auth'])->post('/_sec73/panel', $probe);
        Route::middleware(['web', 'auth:web'])->post('/_sec73/panel-web', $probe);
        Route::middleware(['web', 'auth:customers'])->post('/_sec73/customers', $probe);
        Route::middleware(['web', 'auth:web,customers'])->post('/_sec73/either', $probe);

        $this->actingAs($merchant, 'web');
        $this->actingAs($customer, 'customers');

        $answers = [];
        foreach (['public', 'panel', 'panel-web', 'customers', 'either'] as $route) {
            $answers[$route] = $this->post('/_sec73/' . $route)->json('panel');
        }

        $this->assertSame(
            ['public' => false, 'panel' => true, 'panel-web' => true, 'customers' => false, 'either' => false],
            $answers,
            'A request with a panel login on a PUBLIC route, or on a route that accepts the customers guard, was classified as a panel request.'
        );

        // a request that never went through the router (a console command, a direct call): a `web` user is all there is to go on
        $this->app->instance('request', \Illuminate\Http\Request::create('/x'));
        $this->assertNull(request()->route());
        $this->assertTrue(PlatformUploadPolicy::panelSession());
    }

    /**
     * `custom.auth` is where a Sanctum token's owner is logged into the guard that matches the TOKEN: a merchant's lands in `web` (a panel
     * request), a customer's in `customers` and a delivery driver's in `deliveryboy` (neither is). The same handler, three principals.
     */
    public function test_a_merchants_token_is_a_panel_request_and_a_customers_and_a_drivers_are_not(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver ' . uniqid(), 'email' => 'driver' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'contact' => '0000000', 'created_by' => $store->created_by, 'store_id' => $store->id,
        ]);

        Route::middleware(['api', 'custom.auth'])->post('/_sec73/token', fn () => response()->json(['panel' => PlatformUploadPolicy::panelSession()]));

        $answer = function ($owner) {
            // one request is one app and one session: the guards, and the session the last request logged its owner into, are not this one's
            $this->app['auth']->forgetGuards();
            $this->flushSession();

            return $this->withHeader('Authorization', 'Bearer ' . $owner->createToken('t')->plainTextToken)->post('/_sec73/token')->json('panel');
        };

        $this->assertSame([true, false, false], [$answer($merchant), $answer($customer), $answer($driver)]);
    }

    // ===========================================================================================================================
    // 1. the Store Setting screen: seven six-argument calls behind `auth` and nothing else
    // ===========================================================================================================================

    private function appSettingBody($file): array
    {
        return [
            'app_setting_tab' => 'pills-home-tab',
            'array' => [
                0 => [
                    'section_slug' => 'logo_section', 'array_type' => 'inner-list', 'loop_number' => 1,
                    'inner-list' => [
                        0 => ['field_slug' => 'logo', 'field_type' => 'photo upload', 'field_default_text' => $file],
                    ],
                ],
            ],
        ];
    }

    private function postAppSetting($user, $file)
    {
        return $this->actingAs($user)->post('/app-setting', $this->appSettingBody($file));
    }

    /**
     * A MERCHANT'S STAFF MEMBER WITH NO PERMISSION AT ALL reaches the handler (it asks for none -- a RECORDED GAP, not a decision, see the
     * work queue). The premise below is the reach: if a permission gate is ever put on this handler, the staff member must be given the
     * permission here, or the refusals that follow would be the gate's and not the policy's.
     */
    public function test_the_store_setting_screen_stores_an_image_and_refuses_a_page_even_for_staff_with_no_permission(): void
    {
        [$merchant] = $this->tenant();
        $staff = $this->makeStaff($merchant);

        $this->postAppSetting($merchant, $this->upload('logo.png', $this->png()));

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: the owner\'s legitimate logo reaches the uploader and is stored. Files: ' . json_encode($stored));
        $this->assertStringEndsWith('_logo.png', $stored[0]);

        $this->emptyTheDisk();
        $this->postAppSetting($staff, $this->upload('staff.png', $this->png()));
        $this->assertCount(1, $this->stored(), 'Premise: a staff member with no permission reaches the uploader of this handler (a permission gate has been added: give the staff member the permission here). Files: ' . json_encode($this->stored()));

        $this->emptyTheDisk();
        $this->postAppSetting($staff, $this->upload('page.html', $this->png()));
        $this->assertSame([], $this->stored(), 'A staff member with no permission stored page.html through POST /app-setting: ' . json_encode($this->stored()));

        $this->postAppSetting($staff, $this->upload('shell.php', "<?php echo 'ss-probe'; ?>\n"));
        $this->assertSame([], $this->stored(), 'A staff member with no permission stored shell.php through POST /app-setting: ' . json_encode($this->stored()));
    }

    /** The seven call sites took ANY file before (no validator); an image field takes the images a browser shows, as the other panel image doors do. */
    public function test_the_store_setting_screen_takes_the_image_types_it_took_before_and_not_a_page(): void
    {
        [$merchant] = $this->tenant();

        foreach ([
            'banner.png' => $this->png(), 'banner.gif' => (string) base64_decode(self::GIF), 'banner.webp' => (string) base64_decode(self::WEBP),
            'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="2" height="2"><rect width="2" height="2"/></svg>',
        ] as $name => $bytes) {
            $this->emptyTheDisk();
            $this->postAppSetting($merchant, $this->upload($name, $bytes));

            $this->assertCount(1, $this->stored(), "The owner could no longer store {$name} through the Store Setting screen: " . json_encode(session('error')));
        }

        $this->emptyTheDisk();
        $this->postAppSetting($merchant, $this->upload('banner.html', $this->png()));
        $this->assertSame([], $this->stored());
    }

    /**
     * THE SEVENTH SITE (`uploadThemeMedia`) returned a RedirectResponse where its caller reads `$upload['error']`: HTTP 500 on any
     * refusal -- a branch that could not be reached while the sixth argument skipped the validator. And it had charged the owner's plan
     * for a file that was not stored.
     */
    public function test_a_refused_image_on_the_product_page_setting_answers_cleanly_and_is_not_charged_to_the_plan(): void
    {
        [$merchant] = $this->tenant();
        $post = fn (string $name) => $this->actingAs($merchant)->post('/product-page-setting', [
            'app_setting_tab' => 'x', 'page_name' => 'product_banner',
            'array' => ['section' => ['image' => ['text' => $this->upload($name, $this->png() . str_repeat("\0", 1024 * 1024))]]],
        ]);
        $storage = fn () => (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit');

        $before = $storage();
        $this->assertSame(302, $post('banner.png')->status(), 'Premise: the handler saves the setting and redirects back.');
        $this->assertCount(1, $this->stored(), 'Premise: a legitimate image reaches the uploader and is stored.');
        $this->assertGreaterThan($before, $storage(), 'Premise: the stored image is charged to the plan.');

        $this->emptyTheDisk();
        $charged = $storage();
        $response = $post('banner.html');

        $response->assertOk();
        $this->assertSame([], $this->stored());
        $this->assertSame($charged, $storage(), 'A refused image was charged to the owner\'s plan.');
    }

    // ===========================================================================================================================
    // 2. the receipt at a GUEST checkout
    // ===========================================================================================================================

    /** A store with one product, a shipping row and a GUEST basket keyed on the cart cookie. @return array{0: \App\Models\Store, 1: int, 2: string, 3: array} */
    private function guestBasket(?array $tenant = null): array
    {
        [$merchant, $store] = $tenant ?? $this->tenant();

        if ($tenant === null) {
            foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
                DB::table('settings')->insert(['name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
            }

            DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

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

        return [$store, $shippingId, $cookie, [$merchant, $store]];
    }

    /** One guest checkout with a BASKET OF ITS OWN: an order consumes its basket, so a second request on it never reaches the receipt. */
    private function checkoutWith(UploadedFile $receipt, ?array &$tenant = null, array $extra = [])
    {
        [$store, $shippingId, $cookie, $tenant] = $this->guestBasket($tenant);

        $billing = [
            'firstname' => 'Guest', 'lastname' => 'Shopper', 'email' => 'guest-sec73@example.test',
            'billing_user_telephone' => '555111333', 'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111', 'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];

        return $this->withCookie('cart', $cookie)->post('/' . $store->slug . '/process-order', [
            'payment_type' => 'bank_transfer', 'delivery_id' => $shippingId, 'method_id' => $shippingId,
            'billing_info' => $billing, 'payment_receipt' => $receipt,
        ] + $extra + $billing);
    }

    /**
     * THE RECEIPT IS STORED AT THE TOP OF `getProductStatus()`, BEFORE ITS OWN REFUSALS -- so a receipt can be stored by a request that
     * goes on to be refused, and this door needs no account. A real receipt is the premise and the control: the checkout still writes
     * its order with the receipt on disk, under a name that ends in its extension and carries a random part (two shoppers in one second,
     * or a stranger who knows a name, can no longer land on the same file).
     */
    public function test_a_guest_checkouts_receipt_is_stored_when_it_is_an_image_and_not_when_it_is_a_page(): void
    {
        $tenant = null;
        $this->checkoutWith($this->upload('transfer receipt (1).png', $this->png()), $tenant);

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: a legitimate receipt reaches the uploader and is stored. Files: ' . json_encode($stored) . ' Error: ' . json_encode(session('error')));
        $this->assertMatchesRegularExpression('#^uploads/receipt/transfer_receipt_1_\d{10}_[0-9a-f]{8}\.png$#', $stored[0], 'The receipt\'s stored name ends in its extension, carries a random part, and nothing of the client\'s punctuation survives into it.');
        $this->assertSame(1, (int) DB::table('orders')->where('store_id', $tenant[1]->id)->count(), 'Premise: the checkout wrote its order. Error: ' . json_encode(session('error')));

        foreach (['page.html' => $this->png(), 'photo.pht' => $this->png(), 'logo.svg' => self::SVG, 'photo.png' => "<?php echo 'ss-probe'; ?>\n"] as $name => $bytes) {
            // a basket of its own for the refusal, and another for the control that follows it
            $this->emptyTheDisk();
            $this->checkoutWith($this->upload($name, $bytes), $tenant);
            $this->assertSame([], $this->stored(), "A visitor with no account stored a receipt named {$name}: " . json_encode($this->stored()));

            $this->checkoutWith($this->upload('control.png', $this->png()), $tenant);
            $this->assertCount(1, $this->stored(), "Premise for {$name}: the same checkout stores a PNG, so the refusal above was the receipt's and not an empty basket's. Error: " . json_encode(session('error')));
        }
    }

    /** What the door's own gate (`required|image`) admitted before, minus SVG: a phone's WebP, an old GIF or BMP, a JPEG saved as .jfif. */
    public function test_a_guest_checkout_still_takes_the_receipt_types_its_own_rule_admits(): void
    {
        $tenant = null;
        $jpeg = (string) base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5miiivIP0Q//2Q==');

        foreach ([
            'receipt.webp' => (string) base64_decode(self::WEBP), 'receipt.gif' => (string) base64_decode(self::GIF),
            'receipt.bmp' => (string) base64_decode(self::BMP), 'receipt.jfif' => $jpeg, 'IMG_20241119_102233.JPG' => $jpeg,
        ] as $name => $bytes) {
            $this->emptyTheDisk();
            $this->checkoutWith($this->upload($name, $bytes), $tenant);

            $stored = $this->stored();
            $this->assertCount(1, $stored, "The guest checkout no longer takes a receipt named {$name}: " . json_encode(session('error')));
            $this->assertMatchesRegularExpression('#^uploads/receipt/[A-Za-z0-9_-]+_\d{10}_[0-9a-f]{8}\.[a-z]+$#', $stored[0]);
        }
    }

    /** A name with a PHP extension inside it is made harmless by the receipt's own naming: no dot of the client's survives into the middle. */
    public function test_a_guest_receipts_inner_extension_does_not_survive_into_its_stored_name(): void
    {
        $tenant = null;
        $this->checkoutWith($this->upload('shell.php.png', $this->png()), $tenant);

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'The receipt was refused: ' . json_encode(session('error')));
        $this->assertMatchesRegularExpression('#^uploads/receipt/shell_php_\d{10}_[0-9a-f]{8}\.png$#', $stored[0]);
    }

    // ===========================================================================================================================
    // 4. a merchant's payment slip, opened by the platform operator from a link
    // ===========================================================================================================================

    /** @return array{0: \App\Models\User, 1: int} the merchant and the plan he pays for */
    private function planFixture(): array
    {
        $admin = $this->makePlatformAdmin();
        [$storeA] = $this->guestBasket();
        [$merchant] = $this->tenant();
        $admin->current_store = $storeA->id;
        $admin->save();
        DB::table('settings')->insert(['name' => 'CURRENCY_NAME', 'value' => 'USD', 'created_by' => $admin->id, 'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('super_admin_details');
        $planId = DB::table('plans')->insertGetId(['name' => 'sec73-sellable-' . $this->uniqueFixtureRef(), 'price' => 10, 'themes' => '[]', 'storage_limit' => '100', 'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $planId];
    }

    private function postSlip($merchant, int $planId, string $name, string $bytes)
    {
        return $this->actingAs($merchant, 'web')->post('/plan-pay-with-bank', [
            'plan_id' => Crypt::encrypt($planId),
            'payment_receipt' => $this->upload($name, $bytes),
        ]);
    }

    /**
     * The slip is a merchant paying THE PLATFORM, and the operator reviews it from `plans/receipt.blade.php`. The folder is the one every
     * store's guest receipts share, and the old name was the client's own: a name known to a stranger could be written over.
     */
    public function test_a_plan_payment_slip_is_stored_under_a_name_the_server_chose(): void
    {
        [$merchant, $planId] = $this->planFixture();

        $this->postSlip($merchant, $planId, 'slip (1).png', $this->png());

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: a legitimate slip reaches the uploader and is stored. Error: ' . json_encode(session('error')));
        $this->assertMatchesRegularExpression('#^uploads/receipt/slip_1_\d{10}_[0-9a-f]{8}\.png$#', $stored[0], 'The slip was stored under the client\'s own name, in the folder shared with the guest receipts.');
        $this->assertSame($stored[0], DB::table('plan_orders')->orderByDesc('id')->value('receipt'));
    }

    /**
     * The caller's own rule (`image`) admits SVG in this framework version, and anyone can be a merchant: an SVG slip is script on the
     * application's origin in the OPERATOR's browser, opened from a link with no `download`. D-29.5's reasoning is about a merchant's own
     * logo; this is a different reader.
     */
    public function test_a_plan_payment_slip_is_never_an_svg(): void
    {
        [$merchant, $planId] = $this->planFixture();

        $this->postSlip($merchant, $planId, 'slip.png', $this->png());
        $this->assertCount(1, $this->stored(), 'Premise: a legitimate slip is stored. Error: ' . json_encode(session('error')));

        $this->emptyTheDisk();
        DB::table('plan_orders')->delete();
        $this->postSlip($merchant, $planId, 'slip.svg', self::SVG);

        $this->assertSame([], $this->stored(), 'A panel user stored an SVG as the payment slip the platform operator opens from a link: ' . json_encode($this->stored()));
    }

    // ===========================================================================================================================
    // 5. the shipped app's invoice logo and store image
    // ===========================================================================================================================

    /**
     * `updateStoreOwnerInfo` never called the uploader: it validated the BYTES (`image|mimes:jpeg,png,jpg,gif,svg`) and `move()`d the file
     * under `time() . '_' . basename(<client name>)` -- so PNG bytes named `page.html`, `a.pht` or `shell.php.png` were stored under that
     * name in `public/uploads/<store>/`. The stored name is the client's name reduced to letters, digits, `_` and `-`, and the extension the
     * BYTES decide.
     */
    public function test_the_store_owner_image_is_stored_under_the_extension_its_bytes_decide(): void
    {
        $public = sys_get_temp_dir() . '/sec73-public-' . $this->uniqueFixtureRef();
        mkdir($public, 0777, true);
        $this->temporary[] = $public;
        $previous = app()->publicPath();
        app()->usePublicPath($public);

        try {
            [$merchant, $store] = $this->tenant();
            $token = $merchant->createToken('auth_token')->plainTextToken;
            $post = fn (string $field, string $name) => $this->withHeader('Authorization', 'Bearer ' . $token)->post('/api/admin/updateStoreOwnerInfo', [
                'store_id' => $store->id, $field => $this->upload($name, $this->png()),
            ]);
            $held = fn () => array_map('basename', glob($public . '/uploads/' . $store->id . '/*') ?: []);

            // the two fields are two sites of the handler: each is driven, with every type the rule above them admits (the extension is the
            // BYTES' -- fileinfo says `jpg` for a JPEG, which is what the helper's own list has to hold)
            $legitimate = [
                'logo (1).png' => [$this->png(), 'png', 'logo_1'],
                'photo.JPEG' => [(string) base64_decode(self::JPEG), 'jpg', 'photo'],
                'anim.gif' => [(string) base64_decode(self::GIF), 'gif', 'anim'],
                'logo.svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="2" height="2"><rect width="2" height="2"/></svg>', 'svg', 'logo'],
            ];

            foreach (['invoice_logo', 'store_image'] as $field) {
                foreach ($legitimate as $name => [$bytes, $extension, $base]) {
                    $this->removeDirectory($public . '/uploads');
                    $this->withHeader('Authorization', 'Bearer ' . $token)->post('/api/admin/updateStoreOwnerInfo', ['store_id' => $store->id, $field => $this->upload($name, $bytes)])->assertOk();

                    $this->assertCount(1, $held(), "Premise: a legitimate {$field} named {$name} reaches the handler and is stored.");
                    $this->assertMatchesRegularExpression('/^\d{10}_' . $base . '\.' . $extension . '$/', $held()[0]);
                }

                $this->removeDirectory($public . '/uploads');
                $post($field, 'logo (1).png')->assertOk();
                $this->assertCount(1, $held());

                foreach (['page.html', 'shell.php.png', 'module.pht'] as $name) {
                    $this->removeDirectory($public . '/uploads');
                    $post($field, $name)->assertOk();

                    $this->assertCount(1, $held(), "The {$field} named {$name} was not stored.");
                    $this->assertMatchesRegularExpression('/^\d{10}_[A-Za-z0-9_-]+\.png$/', $held()[0], "The {$field} named {$name} was stored under the client's extension: {$held()[0]}");
                }
            }
        } finally {
            app()->usePublicPath($previous);
        }
    }

    // ===========================================================================================================================
    // 6. P1-SEC-71 -- a shopper's file never replaces another file, and lands in its own store's folder
    // ===========================================================================================================================

    private const REFUSED_SENTENCE = 'An attachment was not accepted. Check its type and size and try again.';
    /** The refund door's own (one refund is allowed per delivered order, so "try again" would send the shopper to a refusal) and the plan-full answer. */
    private const CONTACT_THE_STORE_SENTENCE = 'Your request was sent, but an attachment was not accepted. Check its type and size, then contact the store to add it.';

    private function aTicketOf(Customer $customer, $store): SupportTicket
    {
        return SupportTicket::forceCreate([
            'ticket_id' => 'T-' . $this->uniqueFixtureRef(), 'title' => 'A ticket', 'description' => 'Some text', 'customer_id' => $customer->id,
            'status' => 'Open', 'store_id' => $store->id, 'created_by' => $customer->id,
        ]);
    }

    /**
     * A signed-in SHOPPER as production sees one: the `customers` guard holds the customer and the DEFAULT guard stays `web`, with nobody in it.
     * (`actingAs($customer, 'customers')` also makes `customers` the default guard, which makes `auth()->user()` the customer -- and
     * `getCurrentStore()`, which reads `auth()->user()`, then answers for a customer id as if it were a merchant's. In production a shopper
     * has no `web` user, `getCurrentStore()` answers null, and the ticket's edit and reply paths wrote to a folder no store's id is part of.)
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    private function postTicketEdit($store, Customer $customer, SupportTicket $ticket, array $attachments)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/update-support-ticket/' . $ticket->id, [
            'title' => 'Edited', 'description' => 'Edited text', 'attachments' => $attachments,
        ]);
    }

    private function postReply($store, Customer $customer, SupportTicket $ticket, array $attachments)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/support-ticket/' . $ticket->id, [
            'reply_description' => 'Please see the attached file', 'reply_attachments' => $attachments,
        ]);
    }

    /** A delivered order of this customer, with two lines, from which one refund line can be requested. */
    private function aRefundableOrder($store, Customer $customer)
    {
        return $this->makeOrder($store, [
            'product_order_id' => 'ORD-' . strtoupper(uniqid()), 'customer_id' => $customer->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_price' => 500, 'delivery_price' => 20, 'tax_price' => 20, 'coupon_price' => 0, 'final_price' => 540,
            'product_json' => json_encode([['product_id' => 501, 'name' => 'Alpha', 'variant_id' => 0, 'qty' => 3, 'final_price' => 300], ['product_id' => 502, 'name' => 'Beta', 'variant_id' => 0, 'qty' => 2, 'final_price' => 200]]),
        ]);
    }

    private function postRefund($store, Customer $customer, $order, array $files)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id, 'product_refund_id' => [501], 'quantity' => [1, 0], 'return_price' => ['P', 'P'], 'product_sub_total' => 100,
            'refund_reason' => 'Other', 'custom_refund_reason' => 'x', 'attachments' => $files,
        ]);
    }

    /** @return list<string> the bytes after the PNG header of every stored file, sorted: which uploads are still on the disk */
    private function survivors(): array
    {
        $out = array_map(fn (string $path) => substr((string) Storage::disk('theme')->get($path), strlen($this->png())), $this->stored());
        sort($out);

        return $out;
    }

    /**
     * THE TICKET DOOR STORED THE CLIENT'S OWN NAME, VERBATIM, IN A FOLDER EVERY TICKET OF THE STORE SHARES: two shoppers who both attached
     * `proof.png` were one file, and the second replaced the first under the first one's database row.
     */
    public function test_two_shoppers_attaching_the_same_name_to_a_new_ticket_do_not_replace_each_other(): void
    {
        [, $store] = $this->tenant();
        $one = $this->aCustomerOf($store);
        $two = $this->aCustomerOf($store);

        $this->postTicket($store, $one, [$this->upload('proof.png', $this->png() . 'FIRST')]);
        $this->postTicket($store, $two, [$this->upload('proof.png', $this->png() . 'SECOND')]);

        $stored = $this->stored();
        $this->assertCount(2, $stored, 'The second shopper\'s proof.png replaced the first shopper\'s: ' . json_encode($stored));
        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression('#^uploads/uploads/' . $store->id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#', $path, 'A ticket attachment was stored under the client\'s own name or outside the store\'s own folder.');
        }
        $this->assertSame(['FIRST', 'SECOND'], $this->survivors());

        $rows = SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->attachment, $rows[1]->attachment, 'Both tickets point at the same file.');
    }

    /** THE EDIT PATH ASKED `getCurrentStore()`, WHICH ANSWERS FOR THE PANEL'S GUARD AND IS NULL FOR A SHOPPER: the file went to `uploads/tickets`. */
    public function test_an_attachment_added_by_editing_a_ticket_lands_in_the_stores_own_folder_under_a_server_name(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);

        $this->postTicketEdit($store, $customer, $ticket, [$this->upload('proof.png', $this->png())]);

        $stored = $this->stored();
        $this->assertCount(1, $stored, 'Premise: the edit stores the attachment. Files: ' . json_encode($stored));
        $this->assertMatchesRegularExpression('#^uploads/uploads/' . $store->id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#', $stored[0], 'The edit path stored the attachment outside the store\'s own folder, or under the client\'s own name.');
        $this->assertSame([$stored[0]], json_decode((string) SupportTicket::withoutGlobalScopes()->find($ticket->id)->attachment, true), 'The ticket does not point at the stored file.');
    }

    /** THE REPLY PATH WROTE `uploads/uploads/reply_tickets/<client name>` FOR EVERY STORE: two stores' replies named `proof.png` overwrote each other. */
    public function test_two_stores_replies_naming_the_same_file_do_not_replace_each_other(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $a = $this->aCustomerOf($storeA);
        $b = $this->aCustomerOf($storeB);
        $ticketA = $this->aTicketOf($a, $storeA);
        $ticketB = $this->aTicketOf($b, $storeB);

        $this->postReply($storeA, $a, $ticketA, [$this->upload('proof.png', $this->png() . 'STORE-A')]);
        $this->postReply($storeB, $b, $ticketB, [$this->upload('proof.png', $this->png() . 'STORE-B')]);

        $stored = $this->stored();
        $this->assertCount(2, $stored, 'One store\'s reply replaced the other\'s: ' . json_encode($stored));
        $this->assertSame(['STORE-A', 'STORE-B'], $this->survivors());

        foreach ([[$storeA, 'STORE-A'], [$storeB, 'STORE-B']] as [$store, $marker]) {
            $own = array_values(array_filter($stored, fn (string $path) => str_starts_with($path, 'uploads/uploads/' . $store->id . '/reply_tickets/')));
            $this->assertCount(1, $own, "Store {$store->id}'s reply is not in its own folder. Files: " . json_encode($stored));
            $this->assertMatchesRegularExpression('#/reply_tickets/proof_\d{10}_[0-9a-f]{8}\.png$#', $own[0]);
            $this->assertSame($marker, substr((string) Storage::disk('theme')->get($own[0]), strlen($this->png())));

            $conversation = \App\Models\SupportConversion::withoutGlobalScopes()->where('store_id', $store->id)->first();
            $this->assertNotNull($conversation, 'Premise: the reply was recorded.');
            $this->assertSame([$own[0]], json_decode((string) $conversation->attachments, true), "Store {$store->id}'s conversation does not point at its own file.");
        }
    }

    /** THE REFUND-EVIDENCE DOOR HAD THE STORE'S FOLDER AND THE CLIENT'S NAME: a second refund with `proof.png` replaced the first one's evidence. */
    public function test_two_refund_requests_naming_the_same_evidence_do_not_replace_each_other(): void
    {
        [, $store] = $this->tenant();
        $one = $this->aCustomerOf($store);
        $two = $this->aCustomerOf($store);

        $orderOne = $this->aRefundableOrder($store, $one);
        $orderTwo = $this->aRefundableOrder($store, $two);
        $this->postRefund($store, $one, $orderOne, [$this->upload('proof.png', $this->png() . 'FIRST')]);
        $this->postRefund($store, $two, $orderTwo, [$this->upload('proof.png', $this->png() . 'SECOND')]);

        $this->assertSame(2, DB::table('order_refunds')->count(), 'Premise: both refund requests were recorded.');
        $stored = $this->stored();
        $this->assertCount(2, $stored, 'The second refund\'s proof.png replaced the first\'s: ' . json_encode($stored));
        foreach ($stored as $path) {
            $this->assertMatchesRegularExpression('#^uploads/uploads/' . $store->id . '/order_refund/proof_\d{10}_[0-9a-f]{8}\.png$#', $path);
        }
        $this->assertSame(['FIRST', 'SECOND'], $this->survivors());

        // each refund row points at ITS OWN evidence (TST-06: the files were checked, the rows were not)
        foreach ([[$orderOne, 'FIRST'], [$orderTwo, 'SECOND']] as [$order, $marker]) {
            $row = DB::table('order_refunds')->where('order_id', $order->id)->first();
            $pointers = json_decode((string) $row->attachments, true);
            $this->assertCount(1, $pointers, "The {$marker} refund does not point at exactly one file.");
            $this->assertSame($marker, substr((string) Storage::disk('theme')->get($pointers[0]), strlen($this->png())), "The {$marker} refund points at somebody else's evidence.");
        }
    }

    /**
     * THE REFUSAL NOBODY SAW. All four doors assigned the uploader's answer to a variable and never read it: a shopper whose attachment
     * was refused was told the ticket or the refund had succeeded, and never that the file was dropped. The message is ONE FIXED
     * SENTENCE and nothing of the file's name or of the uploader's own text, because the storefront prints a flash inside a script as raw
     * HTML (`notifier.show('Success', '{!! $message !!}')`).
     */
    public function test_a_refused_attachment_is_reported_to_the_shopper_at_every_door(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $sentence = __(self::REFUSED_SENTENCE);
        $hostile = "page'\"<script>.html";

        // the new ticket
        $response = $this->postTicket($store, $customer, [$this->upload('page.html', $this->png())]);
        $this->assertSame([], $this->stored());
        $this->assertSame(1, SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->count(), 'Premise: the ticket itself is still created.');
        $response->assertSessionHas('error', $sentence);
        $response->assertSessionHas('success');

        // the edit
        $ticket = SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->first();
        $response = $this->postTicketEdit($store, $customer, $ticket, [$this->upload('page.html', $this->png())]);
        $this->assertSame([], $this->stored());
        $response->assertSessionHas('error', $sentence);

        // the reply
        $response = $this->postReply($store, $customer, $ticket, [$this->upload('page.html', $this->png())]);
        $this->assertSame([], $this->stored());
        $response->assertSessionHas('error', $sentence);

        // the refund evidence
        $response = $this->postRefund($store, $customer, $this->aRefundableOrder($store, $customer), [$this->upload('page.html', $this->png())]);
        $this->assertSame(1, DB::table('order_refunds')->count(), 'Premise: the refund request itself is still recorded.');
        $this->assertSame([], $this->stored());
        $response->assertSessionHas('error', __(self::CONTACT_THE_STORE_SENTENCE));   // its own sentence: the same refund cannot be sent again

        // a hostile name changes nothing in what is said
        $response = $this->postReply($store, $customer, $ticket, [$this->upload($hostile, $this->png())]);
        $this->assertSame($sentence, session('error'));
        $this->assertStringNotContainsString('script', (string) session('error'));

        // and an accepted attachment is not reported as refused
        $response = $this->postReply($store, $customer, $ticket, [$this->upload('proof.png', $this->png())]);
        $this->assertCount(1, $this->stored(), 'Premise: a real attachment is stored.');
        $response->assertSessionMissing('error');
    }

    /**
     * THE SINGULAR `attachment` FIELD of both ticket forms (not in the shipped views, but routed and read) had the same two defects, and on the
     * create path an undefined `$dir` whenever the plural field was absent: `uploads/<id>/tickets/proof.png` for everybody.
     */
    public function test_the_single_attachment_field_of_a_ticket_is_stored_the_same_way(): void
    {
        [, $store] = $this->tenant();
        $one = $this->aCustomerOf($store);
        $two = $this->aCustomerOf($store);
        $folder = '#^uploads/' . $store->id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#';
        $sentence = __(self::REFUSED_SENTENCE);

        $create = fn (Customer $who, UploadedFile $file) => $this->asAShopper($who)->post('/' . $store->slug . '/store-ticket', ['title' => 'A problem', 'description' => 'It does not work', 'attachment' => $file]);

        $create($one, $this->upload('proof.png', $this->png() . 'ONE'));
        $create($two, $this->upload('proof.png', $this->png() . 'TWO'));
        $this->assertCount(2, $this->stored(), 'The second shopper\'s single attachment replaced the first\'s (or the create path crashed): ' . json_encode($this->stored()));
        foreach ($this->stored() as $path) {
            $this->assertMatchesRegularExpression($folder, $path);
        }
        $this->assertSame(['ONE', 'TWO'], $this->survivors());

        $this->emptyTheDisk();
        $create($one, $this->upload('page.html', $this->png()))->assertSessionHas('error', $sentence);
        $this->assertSame([], $this->stored());

        $ticket = $this->aTicketOf($one, $store);
        $edit = fn (Customer $who, UploadedFile $file) => $this->asAShopper($who)->post('/' . $store->slug . '/update-support-ticket/' . $ticket->id, ['title' => 'Edited', 'description' => 'Edited text', 'attachment' => $file]);

        $edit($one, $this->upload('proof.png', $this->png() . 'ONE'));
        $edit($one, $this->upload('proof.png', $this->png() . 'TWO'));
        $this->assertCount(2, $this->stored(), 'The second single attachment replaced the first at the edit path: ' . json_encode($this->stored()));
        foreach ($this->stored() as $path) {
            $this->assertMatchesRegularExpression($folder, $path, 'The edit path stored the single attachment outside the store\'s own folder, or under the client\'s own name.');
        }

        $this->emptyTheDisk();
        $edit($one, $this->upload('page.html', $this->png()))->assertSessionHas('error', $sentence);
        $this->assertSame([], $this->stored());
    }

    /** A file PHP itself rejected -- larger than `upload_max_filesize`, a partial upload -- has an EMPTY path and an error code. */
    private function phpRejected(string $name = 'big-photo.png'): UploadedFile
    {
        return new UploadedFile('', $name, null, \UPLOAD_ERR_INI_SIZE, true);
    }

    /**
     * P1-SEC-71 REVIEW, BYP-1: `hasFile()` asks for a non-empty path, so a file PHP itself rejected made it answer false and the whole block under it
     * -- the new counting included -- never ran. The ticket, reply or refund was recorded and the shopper told it had succeeded: the dropped-file-looks-
     * saved defect, for probably the commonest cause of a dropped photo (a phone's 5 MB picture).
     */
    public function test_a_file_php_itself_rejected_is_reported_at_every_door(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $sentence = __(self::REFUSED_SENTENCE);

        $response = $this->postTicket($store, $customer, [$this->phpRejected()]);
        $this->assertSame(1, SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->count(), 'Premise: the ticket itself is still created.');
        $response->assertSessionHas('error', $sentence);

        $ticket = SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->first();
        $this->postTicketEdit($store, $customer, $ticket, [$this->phpRejected()])->assertSessionHas('error', $sentence);
        $this->postReply($store, $customer, $ticket, [$this->phpRejected()])->assertSessionHas('error', $sentence);

        $response = $this->postRefund($store, $customer, $this->aRefundableOrder($store, $customer), [$this->phpRejected()]);
        $this->assertSame(1, DB::table('order_refunds')->count(), 'Premise: the refund request itself is still recorded.');
        $response->assertSessionHas('error', __(self::CONTACT_THE_STORE_SENTENCE));

        $this->assertSame([], $this->stored());
    }

    /** A file PHP rejected next to a good one has no path, and `getSize()` on it raises: the good one must still be stored and the bad one said. */
    public function test_a_file_php_rejected_next_to_a_good_one_does_not_stop_the_good_one(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $sentence = __(self::REFUSED_SENTENCE);

        $response = $this->postTicket($store, $customer, [$this->phpRejected(), $this->upload('proof.png', $this->png())]);
        $this->assertCount(1, $this->stored(), 'Premise: the good file is stored and nothing crashed. Files: ' . json_encode($this->stored()));
        $response->assertSessionHas('error', $sentence);
        $response->assertSessionHas('success');

        $ticket = SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->first();
        $this->postTicketEdit($store, $customer, $ticket, [$this->phpRejected(), $this->upload('second.png', $this->png())])->assertSessionHas('error', $sentence);
        $this->assertCount(2, $this->stored(), 'The edit did not store the good file beside the rejected one: ' . json_encode($this->stored()));
    }

    /**
     * R71-TEN-01 (tenancy lens): the route's store for a shopper who ALSO holds a panel login. `getCurrentStore()` reads the DEFAULT guard (`web`);
     * a visitor who registered a merchant account holds both, and then it answers for THAT merchant. Every other edit and reply case signs the
     * shopper in with asAShopper(), where the default guard is EMPTY and `getCurrentStore()` is null -- so a `(getCurrentStore() ?: $store->id)`
     * fallback answered identically there and wrote into the OTHER tenant's folder as soon as a web login existed.
     */
    private function asAShopperHoldingAPanelLogin(Customer $customer, \App\Models\User $merchantOfAnotherTenant, int $theirStoreId): self
    {
        $this->actingAs($merchantOfAnotherTenant, 'web');
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');       // the default guard is the merchant's, as in a browser that holds both sessions

        $this->assertSame($theirStoreId, (int) getCurrentStore(), 'Premise: getCurrentStore() answers for the OTHER tenant.');

        return $this;
    }

    public function test_a_shopper_who_also_holds_a_panel_login_still_stores_in_the_routes_store_at_every_door(): void
    {
        [, $store] = $this->aStoreWhoseIdIsNobodyElses();     // so that the route's store is not store 1 and not its owner's user id either (TST-01)
        [$other, $otherStore] = $this->tenant();
        $this->assertNotSame((int) $store->id, (int) $otherStore->id, 'Premise: two stores.');
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);
        $order = $this->aRefundableOrder($store, $customer);
        $slug = '/' . $store->slug;

        $this->asAShopperHoldingAPanelLogin($customer, $other, (int) $otherStore->id)->post($slug . '/store-ticket', ['title' => 'A problem', 'description' => 'It does not work', 'attachments' => [$this->upload('one.png', $this->png())]]);
        $this->asAShopperHoldingAPanelLogin($customer, $other, (int) $otherStore->id)->post($slug . '/update-support-ticket/' . $ticket->id, ['title' => 'Edited', 'description' => 'Edited text', 'attachments' => [$this->upload('two.png', $this->png())]]);
        $this->asAShopperHoldingAPanelLogin($customer, $other, (int) $otherStore->id)->post($slug . '/support-ticket/' . $ticket->id, ['reply_description' => 'See the attached', 'reply_attachments' => [$this->upload('three.png', $this->png())]]);
        $this->asAShopperHoldingAPanelLogin($customer, $other, (int) $otherStore->id)->post($slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id, 'product_refund_id' => [501], 'quantity' => [1, 0], 'return_price' => ['P', 'P'], 'product_sub_total' => 100,
            'refund_reason' => 'Other', 'custom_refund_reason' => 'x', 'attachments' => [$this->upload('four.png', $this->png())],
        ]);

        $stored = $this->stored();
        $this->assertCount(4, $stored, 'Premise: all four doors stored their file. Files: ' . json_encode($stored));
        foreach ($stored as $path) {
            $this->assertStringStartsWith('uploads/uploads/' . $store->id . '/', $path, 'A shopper holding another tenant\'s panel login stored a file outside the route\'s store: ' . $path);
            $this->assertStringNotContainsString('uploads/uploads/' . $otherStore->id . '/', $path, 'A file landed in the OTHER tenant\'s folder: ' . $path);
        }
    }

    /** R71-TEN-02: the merchant's plan is full. The state of the plan is not a shopper's business: the file is refused like any other, said without naming it. */
    public function test_a_full_plan_is_not_told_to_the_shopper_and_does_not_stop_the_rest_of_the_edit(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $customer->created_by = $merchant->id;      // as a customer made at a guest checkout is: creatorId() is then the store's owner
        $customer->save();
        $merchant->storage_limit = 1000;            // the plan's storage_limit is 100
        $merchant->save();
        $contact = __(self::CONTACT_THE_STORE_SENTENCE);

        $response = $this->postTicket($store, $customer, [$this->upload('proof.png', $this->png())]);
        $this->assertSame([], $this->stored(), 'Premise: nothing is stored when the plan is full.');
        $this->assertSame(1, SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->count(), 'Premise: the ticket itself is created.');
        $response->assertSessionHas('error', $contact);
        $this->assertStringNotContainsString('plan', strtolower((string) session('error')), 'The shopper was told about the merchant\'s plan.');

        $ticket = SupportTicket::withoutGlobalScopes()->where('store_id', $store->id)->first();
        $response = $this->postTicketEdit($store, $customer, $ticket, [$this->upload('proof.png', $this->png())]);
        $response->assertSessionHas('error', $contact);
        $this->assertSame('Edited', SupportTicket::withoutGlobalScopes()->find($ticket->id)->title, 'A full plan stopped the rest of the edit from being saved.');
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the review's tests lens (TST-01 ... TST-06, TST-15): what the first form of these tests could not tell apart
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * A store whose id is neither 1, nor its owner's user id: the first form built its store first, so "the route's store", "store 1", "its owner's
     * user id" and `getCurrentStore() ?? 1` (what `Utility::upload_file()` writes for a caller with no store) were ONE number, and four ways of
     * choosing the wrong folder passed every folder assertion (TST-01; each was executed as a mutant and survived).
     */
    private function aStoreWhoseIdIsNobodyElses(): array
    {
        $this->tenant();                         // store 1, owned by user 1
        $this->makeMerchant();                   // user 2 owns nothing
        [$merchant, $store] = $this->tenant();   // store 2, owned by user 3

        $this->assertNotSame((int) $store->id, (int) $store->created_by, 'Premise: the store id is not its owner\'s user id.');
        $this->assertGreaterThan(1, (int) $store->id, 'Premise: the store id is not 1.');

        return [$merchant, $store];
    }

    /** One request to each door with the fields the caller names (the plural field, the single one, or both), as a shopper as production sees one. */
    private function createWith(Customer $customer, $store, array $fields)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/store-ticket', ['title' => 'A problem', 'description' => 'It does not work'] + $fields);
    }

    private function editWith(Customer $customer, $store, SupportTicket $ticket, array $fields)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/update-support-ticket/' . $ticket->id, ['title' => 'Edited', 'description' => 'Edited text'] + $fields);
    }

    private function replyWith(Customer $customer, $store, SupportTicket $ticket, array $fields)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/support-ticket/' . $ticket->id, ['reply_description' => 'Please see the attached file'] + $fields);
    }

    private function refundWith(Customer $customer, $store, $order, array $fields)
    {
        return $this->asAShopper($customer)->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id, 'product_refund_id' => [501], 'quantity' => [1, 0], 'return_price' => ['P', 'P'], 'product_sub_total' => 100,
            'refund_reason' => 'Other', 'custom_refund_reason' => 'x',
        ] + $fields);
    }

    /** TST-01: every door and field writes under the route's store when no id in the fixture equals another one. */
    public function test_every_door_writes_under_the_routes_store_when_no_id_in_the_fixture_equals_another(): void
    {
        [, $store] = $this->aStoreWhoseIdIsNobodyElses();
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);
        $id = $store->id;

        $cases = [
            'create (plural)' => [fn () => $this->createWith($customer, $store, ['attachments' => [$this->upload('proof.png', $this->png())]]), '#^uploads/uploads/' . $id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#'],
            'create (single)' => [fn () => $this->createWith($customer, $store, ['attachment' => $this->upload('proof.png', $this->png())]), '#^uploads/' . $id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#'],
            'edit (plural)' => [fn () => $this->editWith($customer, $store, $ticket, ['attachments' => [$this->upload('proof.png', $this->png())]]), '#^uploads/uploads/' . $id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#'],
            'edit (single)' => [fn () => $this->editWith($customer, $store, $ticket, ['attachment' => $this->upload('proof.png', $this->png())]), '#^uploads/' . $id . '/tickets/proof_\d{10}_[0-9a-f]{8}\.png$#'],
            'reply' => [fn () => $this->replyWith($customer, $store, $ticket, ['reply_attachments' => [$this->upload('proof.png', $this->png())]]), '#^uploads/uploads/' . $id . '/reply_tickets/proof_\d{10}_[0-9a-f]{8}\.png$#'],
            'refund' => [fn () => $this->refundWith($customer, $store, $this->aRefundableOrder($store, $customer), ['attachments' => [$this->upload('proof.png', $this->png())]]), '#^uploads/uploads/' . $id . '/order_refund/proof_\d{10}_[0-9a-f]{8}\.png$#'],
        ];

        foreach ($cases as $door => [$request, $pattern]) {
            $this->emptyTheDisk();
            $request();
            $this->assertCount(1, $this->stored(), "{$door}: premise, one file stored. Files: " . json_encode($this->stored()));
            $this->assertMatchesRegularExpression($pattern, $this->stored()[0], "{$door}: the file is not under the route's own store folder (store {$id}).");
        }
    }

    /**
     * TST-03: an ACCEPTED attachment is never reported as refused, at any door and for either field. The first form checked this at the reply door
     * alone, so a counter that over-counted, or a branch that took the refusal for an acceptance, survived at the other five.
     */
    public function test_an_accepted_attachment_is_never_reported_as_refused_at_any_door_or_field(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);

        $doors = [
            'create (plural)' => fn () => $this->createWith($customer, $store, ['attachments' => [$this->upload('proof.png', $this->png())]]),
            'create (single)' => fn () => $this->createWith($customer, $store, ['attachment' => $this->upload('proof.png', $this->png())]),
            'edit (plural)' => fn () => $this->editWith($customer, $store, $ticket, ['attachments' => [$this->upload('proof.png', $this->png())]]),
            'edit (single)' => fn () => $this->editWith($customer, $store, $ticket, ['attachment' => $this->upload('proof.png', $this->png())]),
            'reply' => fn () => $this->replyWith($customer, $store, $ticket, ['reply_attachments' => [$this->upload('proof.png', $this->png())]]),
            'refund' => fn () => $this->refundWith($customer, $store, $this->aRefundableOrder($store, $customer), ['attachments' => [$this->upload('proof.png', $this->png())]]),
        ];

        foreach ($doors as $door => $request) {
            $this->emptyTheDisk();
            $response = $request();
            $this->assertCount(1, $this->stored(), "Premise: {$door} stored the attachment.");
            $response->assertSessionHas('success');
            $response->assertSessionMissing('error');
        }
    }

    /**
     * TST-04: SEVERAL files in one request, as every shipped form posts them (`attachments[]` with `multiple`): the good ones are stored, once each,
     * and the bad one is said -- wherever it stands in the list. The first form posted ONE file everywhere, so the per-file counter, the per-file
     * index and "the first refusal ends the loop" were untested.
     */
    public function test_several_files_in_one_request_store_the_good_ones_and_say_the_bad_one_at_every_door(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);
        $sentence = __(self::REFUSED_SENTENCE);
        $contact = __(self::CONTACT_THE_STORE_SENTENCE);      // the refund door's own: the same refund cannot be sent again

        $files = fn (array $order) => array_map(fn (string $kind) => match ($kind) {
            'bad' => $this->upload('page.html', $this->png()),
            'a' => $this->upload('a.png', $this->png() . 'A'),
            'b' => $this->upload('b.png', $this->png() . 'B'),
        }, $order);

        $doors = [
            'create' => [fn (array $list) => $this->createWith($customer, $store, ['attachments' => $list]), $sentence],
            'edit' => [fn (array $list) => $this->editWith($customer, $store, $ticket, ['attachments' => $list]), $sentence],
            'reply' => [fn (array $list) => $this->replyWith($customer, $store, $ticket, ['reply_attachments' => $list]), $sentence],
            'refund' => [fn (array $list) => $this->refundWith($customer, $store, $this->aRefundableOrder($store, $customer), ['attachments' => $list]), $contact],
        ];

        foreach ($doors as $door => [$request, $expected]) {
            foreach ([['bad', 'a', 'b'], ['a', 'bad', 'b'], ['a', 'b', 'bad']] as $order) {
                $this->emptyTheDisk();
                $response = $request($files($order));
                $this->assertSame(['A', 'B'], $this->survivors(), "{$door} [" . implode(',', $order) . ']: the two good files are stored, each once.');
                $response->assertSessionHas('error', $expected);
            }
        }
    }

    /** TST-04: two GOOD files in one request are two files, each stored from its own index. */
    public function test_two_good_files_in_one_request_are_two_files_at_the_reply_and_refund_doors(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);

        $this->replyWith($customer, $store, $ticket, ['reply_attachments' => [$this->upload('a.png', $this->png() . 'A'), $this->upload('b.png', $this->png() . 'B')]]);
        $this->assertSame(['A', 'B'], $this->survivors(), 'reply');

        $this->emptyTheDisk();
        $this->refundWith($customer, $store, $this->aRefundableOrder($store, $customer), ['attachments' => [$this->upload('a.png', $this->png() . 'A'), $this->upload('b.png', $this->png() . 'B')]]);
        $this->assertSame(['A', 'B'], $this->survivors(), 'refund');
    }

    /** TST-05: BOTH fields posted at once (the counter is shared between the two branches): a refusal of either is said. */
    public function test_a_refusal_of_either_field_is_said_when_both_are_posted(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);
        $ticket = $this->aTicketOf($customer, $store);
        $sentence = __(self::REFUSED_SENTENCE);

        $this->createWith($customer, $store, ['attachments' => [$this->upload('page.html', $this->png())], 'attachment' => $this->upload('proof.png', $this->png())])
            ->assertSessionHas('error', $sentence);

        $this->editWith($customer, $store, $ticket, ['attachment' => $this->upload('page.html', $this->png()), 'attachments' => [$this->upload('proof.png', $this->png())]])
            ->assertSessionHas('error', $sentence);
    }

    /** TST-06: the refund's evidence, and the single field of a new ticket, are READ BACK from the row they belong to. */
    public function test_the_stored_file_is_linked_to_the_row_it_belongs_to(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->aCustomerOf($store);

        $order = $this->aRefundableOrder($store, $customer);
        $this->refundWith($customer, $store, $order, ['attachments' => [$this->upload('proof.png', $this->png() . 'E')]]);
        $stored = $this->stored();
        $this->assertCount(1, $stored);
        $row = DB::table('order_refunds')->where('order_id', $order->id)->first();
        $this->assertNotNull($row, 'Premise: the refund was recorded.');
        $this->assertSame($stored, json_decode((string) $row->attachments, true), 'The refund row does not point at its evidence.');

        $this->emptyTheDisk();
        $this->createWith($customer, $store, ['attachment' => $this->upload('proof.png', $this->png() . 'S')]);
        $stored = $this->stored();
        $this->assertCount(1, $stored);
        $ticket = DB::table('support_tickets')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertSame($stored[0], $ticket->attachment, 'The new ticket does not point at its single attachment.');
    }

    /**
     * TST-15: the refusal sentences sit in a script string, printed as raw HTML, in three of the four storefront layouts
     * (`notifier.show('Error', '{!! $message !!}')`): a translator's apostrophe or angle bracket would break the page's script. Both languages must
     * carry a text of their own (an Arabic store would otherwise show the English one), and none may hold a quote, a bracket or a backslash.
     */
    public static function theRefusalSentences(): array
    {
        return [
            'the refusal' => ['An attachment was not accepted. Check its type and size and try again.'],
            'the one that says to contact the store' => ['Your request was sent, but an attachment was not accepted. Check its type and size, then contact the store to add it.'],
        ];
    }

    #[DataProvider('theRefusalSentences')]
    public function test_a_refusal_sentence_has_a_text_in_both_languages_with_nothing_a_script_string_would_choke_on(string $key): void
    {
        foreach (['en', 'ar'] as $language) {
            $texts = json_decode((string) file_get_contents(base_path("resources/lang/{$language}.json")), true, 512, JSON_THROW_ON_ERROR);

            $this->assertArrayHasKey($key, $texts, "resources/lang/{$language}.json has no text for: {$key}");
            $this->assertNotSame('', trim((string) $texts[$key]), "{$language}: the text is empty.");
            $this->assertDoesNotMatchRegularExpression('/[\'"<>\\\\]/', (string) $texts[$key], "{$language}: the text holds a quote, an angle bracket or a backslash.");

            if ($language === 'ar') {
                $this->assertNotSame($key, $texts[$key], 'The Arabic text is the English one.');
            }
        }
    }

    // --- the vendored CheckoutAttachment module's door -----------------------------------------------------------------------

    private function enableCheckoutAttachment(array $tenant): void
    {
        [$merchant, $store] = $tenant;

        // `module_is_active()` asks, on a storefront route, whether the STORE OWNER'S PLAN lists the module
        DB::table('plans')->where('id', $merchant->plan_id)->update(['modules' => 'CheckoutAttachment']);
        Cache::forget('user_by_store_' . $store->created_by);

        \App\Models\AddOnManager::create([
            'module' => 'CheckoutAttachment', 'name' => 'Checkout Attachment', 'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => 'checkout-attachment',
        ]);
        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['CheckoutAttachment']);

        foreach (glob(base_path('packages/workdo/CheckoutAttachment/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('checkout_attachments'), 'Premise: the module\'s migration ran.');
        $this->assertTrue(class_exists(\Workdo\CheckoutAttachment\app\Models\CheckoutAttachment::class), 'Premise: the module\'s model is loadable.');
    }

    /**
     * AN ANONYMOUS VISITOR CAN REPLACE A MERCHANT'S PRODUCT-GALLERY IMAGE. At a checkout the module stores each `attachment[]` file under
     * `uploads/<store id>/<the client's own name>` -- which `keyWiseUpload_file()` writes under `uploads/` again, the folder the product
     * gallery's images are written to (names the storefront prints in its own HTML). The module is vendored and is not edited, so the
     * uploader refuses to let a stranger's file be a replacement.
     */
    public function test_a_visitors_checkout_attachment_cannot_replace_a_product_gallery_image(): void
    {
        $tenant = null;
        $this->checkoutWith($this->upload('transfer.png', $this->png()), $tenant);   // builds the store, its settings and a first order
        $store = $tenant[1];
        $this->enableCheckoutAttachment($tenant);

        $gallery = 'uploads/uploads/' . $store->id . '/gallery-1.png';
        $this->emptyTheDisk();
        Storage::disk('theme')->put($gallery, 'THE MERCHANT\'S IMAGE');

        $this->checkoutWith($this->upload('transfer.png', $this->png()), $tenant, ['attachment' => [$this->upload('gallery-1.png', $this->png() . 'THE VISITOR\'S FILE')]]);

        $this->assertSame('THE MERCHANT\'S IMAGE', Storage::disk('theme')->get($gallery), 'A visitor at a checkout replaced a product-gallery image by naming an attachment after it.');

        $row = DB::table('checkout_attachments')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($row, 'Premise: the module stored the visitor\'s attachment and recorded it. Files: ' . json_encode($this->stored()) . ' Error: ' . json_encode(session('error')));
        $paths = json_decode((string) $row->attachment, true);
        $this->assertCount(1, $paths);
        $this->assertNotSame($gallery, $paths[0]);
        $this->assertMatchesRegularExpression('#^uploads/uploads/' . $store->id . '/gallery-1_\d{10}_[0-9a-f]{16}\.png$#', $paths[0]);
        $this->assertSame($this->png() . 'THE VISITOR\'S FILE', Storage::disk('theme')->get($paths[0]));
    }
}
