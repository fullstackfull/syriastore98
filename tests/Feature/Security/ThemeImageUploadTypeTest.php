<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\ThemeImageUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-72 -- `upload_theme_image()` STORED WHATEVER IT WAS HANDED, UNDER THE CALLER'S OWN EXTENSION.
 * ===================================================================================================
 *
 * The helper (app/Helper/helper.php) is the image upload of seven call sites: the storefront customer's avatar
 * (`POST api/{slug}/update-user-image`), three payment-setting handlers of the admin API, the web payment settings, and the two
 * vendored sign-in settings handlers. It applied NO type rule, took the stored extension from `getClientOriginalExtension()`, and
 * wrote through the `theme` disk, whose root is the application root -- which on the deployed host is the document root (.htaccess,
 * P1-SEC-40: "Apache returns ANY file that really exists").
 *
 * And the first of those call sites needs nothing but a registration: `POST api/{slug}/register` is public (it carries the API
 * group's 120-a-minute throttle and nothing else) and answers with a Bearer token; the avatar handler asked `'image' =>
 * 'required'` and nothing more; and its response RETURNS THE STORED PATH. So an anonymous visitor could put a file of any type,
 * under the extension of their choice, at a known path below the document root. Whether a server then runs or renders it is the
 * server's configuration and is not in this repository; what this file pins is that the file is never stored.
 *
 * MEASURED ON THE UNCHANGED CODE, before any fix existed: every one of the first eleven refusal cases below stored its file
 * (`shell.php` as `.php`, `page.html` as `.html`, `.htaccess` as `<name>.htaccess`, ...), the avatar handler answered 500 to a
 * string, and a refused upload replaced the avatar it was meant to leave alone.
 *
 * EVERY CASE DRIVES A REAL FILE. `UploadedFile::fake()` reports its type from its NAME, so a rule that sniffs the bytes would be
 * judged by the name and the case would pass or fail for the wrong reason; these are `UploadedFile` objects over real bytes on
 * disk. The `theme` disk is faked for every test but one, so nothing here can leave a file in the tracked `uploads/` directory --
 * least of all the ones this file is about. The one test that needs the real disk (a replaced avatar's old file is unlinked
 * through `StoredUpload`, which resolves real paths) uses a store re-keyed to an id no real folder has, and removes only that.
 */
class ThemeImageUploadTypeTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVQI12P8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==';
    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=';
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    private const WEBP = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';
    private const PHP = "<?php echo 'ss-probe'; ?>\n";
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>document.title="ss-probe"</script></svg>';
    private const HTML = "<!doctype html><html><body><script>document.title='ss-probe'</script></body></html>\n";

    /** `uploads/` is tracked: the one real-disk test uses a store on this id, which no real folder has (986xxx and 987xxx are taken). */
    private const REAL_DISK_STORE_ID = 985001;

    /** @var list<string> temporary files to remove */
    private array $temporary = [];

    private bool $realDiskFolderIsOurs = false;

    /** @var list<string>|null the loose files in `uploads/` before the real-disk test wrote anything; null for every other test */
    private ?array $looseFilesBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('theme');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        if ($this->realDiskFolderIsOurs) {
            File::deleteDirectory(base_path('uploads/' . self::REAL_DISK_STORE_ID));
        }

        // Against the code this file was written to fail on, the avatar lands LOOSE in the tracked `uploads/`: the first such run
        // left a PNG there. Whatever this test added at the top level goes, pass or fail -- and nothing that was already there.
        foreach ($this->looseFilesBefore === null ? [] : array_diff($this->looseFilesInUploads(), $this->looseFilesBefore) as $name) {
            @unlink(base_path('uploads/' . $name));
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------------------------------------------------------------

    /** @return list<string> the names of the files sitting directly in `uploads/`, in no store's folder */
    private function looseFilesInUploads(): array
    {
        return array_values(array_filter(scandir(base_path('uploads')) ?: [], fn ($name) => is_file(base_path('uploads/' . $name))));
    }

    /** An upload as PHP hands it to the framework: real bytes on disk, the client's own name, the content type the client claims. */
    private function upload(string $clientName, string $bytes, ?string $claimedType = null): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ssup');
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new UploadedFile($path, $clientName, $claimedType, null, true);
    }

    /** Real PNG bytes padded to a size: the type is read from the head of the file, so the padding does not change it. */
    private static function pngOf(int $bytes): string
    {
        $png = (string) base64_decode(self::PNG);

        return $png . str_repeat("\0", max(0, $bytes - strlen($png)));
    }

    /**
     * THE WHOLE CHAIN FROM THE OPEN INTERNET: register through the public route, take the token it answers with.
     *
     * @return array{0: Store, 1: Customer, 2: string, 3: User} the store, the customer, the token, the store's owner
     */
    private function selfRegisteredCustomer(?User $merchant = null, ?Store $store = null): array
    {
        if ($store === null) {
            [$merchant, $store] = $this->makeTenant();
        }

        $response = $this->post('/api/' . $store->slug . '/register', [
            'first_name' => 'Any', 'last_name' => 'Visitor',
            'email' => 'visitor-' . $this->uniqueFixtureRef() . '@example.test',
            'password' => 'not-a-real-password', 'mobile' => '0912345678',
        ]);

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', $token, 'Premise: public registration answers with a Bearer token. Body: ' . $response->getContent());

        $customer = Customer::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));
        $this->assertSame((int) $store->id, (int) $customer->store_id, 'Premise: the visitor became a customer of the store they registered on.');
        $this->app['auth']->forgetGuards();          // the next request must be authenticated by the token alone

        return [$store, $customer, $token, $merchant];
    }

    private function postAvatar(Store $store, Customer $customer, string $token, $image)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
            ->post('/api/' . $store->slug . '/update-user-image', ['customer_id' => $customer->id, 'image' => $image]);
    }

    private function avatarColumn(Customer $customer): ?string
    {
        return DB::table('customers')->where('id', $customer->id)->value('profile_image');
    }

    private function chargedStorage(User $merchant): float
    {
        return (float) DB::table('users')->where('id', $merchant->id)->value('storage_limit');
    }

    /** A plan with this much storage (MB), and the merchant already using `$used` of it. */
    private function givePlan(User $merchant, int $planMb, float $used = 0.0): void
    {
        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'sec72-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => (string) $planMb, 'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->storage_limit = $used;
        $merchant->save();
    }

    /** What the stored name has to look like: `<key><Ymd>_<time>_<12 hex>.<ext>` in the store's own folder. Nothing of the client's. */
    private function assertServerChosenPath(string $path, int $storeId, string $extension, string $key = '0'): void
    {
        $this->assertMatchesRegularExpression(
            '#^uploads/' . $storeId . '/' . preg_quote($key, '#') . '\d{8}_\d{10}_[0-9a-f]{12}\.' . $extension . '$#',
            $path,
            'The stored path is not the server-chosen name in the store\'s own folder.'
        );
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the storefront avatar: what a visitor who has only registered can and cannot store
    // ---------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2?: string}> the client's file name, the bytes, the content type the client claims */
    public static function filesACustomerMayNotStore(): array
    {
        $png = (string) base64_decode(self::PNG);

        return [
            'a PHP file' => ['shell.php', self::PHP],
            'a PHP file under an older extension' => ['shell.phtml', self::PHP],
            'a PHP archive extension' => ['shell.phar', self::PHP],
            'an HTML page' => ['page.html', self::HTML],
            'a script' => ['code.js', "document.title='ss-probe';\n"],
            'a per-directory server override' => ['.htaccess', "AddType application/x-httpd-php .png\n"],
            'an SVG carrying a script' => ['logo.svg', self::SVG],
            'PHP bytes under an image name' => ['photo.png', self::PHP],
            'image bytes under a PHP name' => ['photo.php', $png],
            'image bytes under an HTML name' => ['photo.html', $png],
            'image bytes with no extension at all' => ['photo', $png],
            // added with the fix; each is refused for a reason of its own
            'a PHP file that claims to be an image' => ['shell.php', self::PHP, 'image/png'],
            'PHP bytes that claim to be an image, under an image name' => ['photo.jpg', self::PHP, 'image/jpeg'],
            'PHP bytes behind a double extension' => ['shell.php.jpg', self::PHP],
            'an HTML page under an image name' => ['page.png', self::HTML],
            'SVG bytes under a raster name' => ['logo.png', self::SVG],
            'an HTML page under an SVG name' => ['page.svg', self::HTML],
            'image bytes under an SVG name' => ['photo.svg', $png],
            'image bytes under an uppercase PHP name' => ['PHOTO.PHP', $png],
            'image bytes under a name that ends in a dot' => ['photo.png.', $png],
        ];
    }

    #[DataProvider('filesACustomerMayNotStore')]
    public function test_a_self_registered_visitor_cannot_store_anything_but_a_raster_image_as_their_avatar(string $clientName, string $bytes, ?string $claimedType = null): void
    {
        [$store, $customer, $token, $merchant] = $this->selfRegisteredCustomer();
        $this->givePlan($merchant, 100);

        // 300 KB of it, so that a charge to the merchant's plan would be a visible figure and not 0.00.
        $response = $this->postAvatar($store, $customer, $token, $this->upload($clientName, $bytes . str_repeat("\n", 300 * 1024), $claimedType));

        $this->assertSame([], Storage::disk('theme')->allFiles(),
            "A file named {$clientName} was stored below the application root by a visitor who had only registered. The response was: " . $response->getContent());
        $this->assertSame(200, $response->status(), 'A refused upload is HTTP 200 with status 0 -- never a 401 (the client signs out on one) and never a crash.');
        $this->assertSame(0, (int) $response->json('status'), 'The refusal must be the error envelope.');
        $this->assertStringContainsString('must be a file of type: jpg, jpeg, png, gif, webp', (string) $response->json('message'),
            'The refusal does not say which types are accepted -- or the file was refused for some other reason than its type.');
        $this->assertNull($this->avatarColumn($customer), 'The avatar column was changed by a refused upload.');
        $this->assertSame(0.0, $this->chargedStorage($merchant), 'The merchant\'s plan storage was charged for a file that was refused.');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> the client's file name, the base64 of the bytes, the extension it must be stored under */
    public static function imagesACustomerMayStore(): array
    {
        return [
            'a PNG' => ['me.png', self::PNG, 'png'],
            'a PNG under an uppercase extension' => ['ME.PNG', self::PNG, 'png'],
            'a JPEG named .jpg' => ['me.jpg', self::JPEG, 'jpg'],
            'a JPEG named .jpeg' => ['me.jpeg', self::JPEG, 'jpg'],
            'a GIF' => ['me.gif', self::GIF, 'gif'],
            'a WebP' => ['me.webp', self::WEBP, 'webp'],
            // THE EXTENSION IS THE SERVER'S: an image under another image's name is accepted -- as what it IS
            'a JPEG named .png' => ['holiday.png', self::JPEG, 'jpg'],
            'a PNG named .gif' => ['holiday.gif', self::PNG, 'png'],
            // and nothing of the client's name reaches the disk, whatever it spells
            'a PNG whose name carries a PHP extension inside it' => ['shell.php.png', self::PNG, 'png'],
            'a PNG whose name walks up the tree' => ['../../../public/index.png', self::PNG, 'png'],
        ];
    }

    #[DataProvider('imagesACustomerMayStore')]
    public function test_a_real_image_is_stored_in_the_stores_folder_under_the_servers_own_name(string $clientName, string $base64, string $storedExtension): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();

        $response = $this->postAvatar($store, $customer, $token, $this->upload($clientName, (string) base64_decode($base64)));

        $response->assertStatus(200);
        $this->assertSame(1, (int) $response->json('status'), "A real image named {$clientName} was refused: " . $response->getContent());

        $stored = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $stored, 'Exactly one file is stored for one upload.');
        $this->assertServerChosenPath($stored[0], (int) $store->id, $storedExtension);
        $this->assertSame($stored[0], $this->avatarColumn($customer), 'The avatar column names the stored file.');
        $this->assertSame($stored[0], $response->json('message'), 'The response names the stored file.');
        $this->assertSame((string) base64_decode($base64), Storage::disk('theme')->get($stored[0]), 'The stored bytes are not the uploaded image.');
    }

    /**
     * A REFUSED REPLACEMENT LEAVES THE PREVIOUS AVATAR ALONE. The handler used to take whatever the helper returned, delete the old
     * file and store the new path -- so once the helper can refuse, an unchecked refusal would erase the avatar it was replacing.
     */
    public function test_a_refused_replacement_keeps_the_previous_avatar(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $this->postAvatar($store, $customer, $token, $this->upload('me.png', (string) base64_decode(self::PNG)));
        $before = $this->avatarColumn($customer);
        $this->assertNotNull($before, 'Premise: the customer has an avatar to lose.');

        $response = $this->postAvatar($store, $customer, $token, $this->upload('shell.php', self::PHP));

        $this->assertSame(0, (int) $response->json('status'));
        $this->assertSame($before, $this->avatarColumn($customer), 'A refused upload cleared or replaced the previous avatar.');
        $this->assertSame([$before], Storage::disk('theme')->allFiles(), 'The previous avatar file is gone, or another file was stored.');
    }

    /** @return array<string, array{0: mixed}> */
    public static function valuesThatAreNotAFile(): array
    {
        return [
            'a path' => ['uploads/logo/logo-dark.png'],
            'a list of strings' => [['uploads/logo/logo-dark.png', 'x']],
            'a number' => [7],
        ];
    }

    /** A value that is not an uploaded file at all used to reach `->getSize()` on it: a 500 with a stack trace. */
    #[DataProvider('valuesThatAreNotAFile')]
    public function test_a_value_that_is_not_a_file_is_refused_and_not_a_crash($value): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();

        $response = $this->postAvatar($store, $customer, $token, $value);

        $this->assertSame(200, $response->status(), 'A value in place of the file crashed the handler: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(0, (int) $response->json('status'));
        $this->assertSame([], Storage::disk('theme')->allFiles());
        $this->assertNull($this->avatarColumn($customer));
    }

    /** The ceiling is the constant, to the kilobyte: 2048 KB is stored and 2049 KB is not. */
    public function test_an_avatar_over_the_size_ceiling_is_refused_and_one_at_the_ceiling_is_stored(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $ceiling = ThemeImageUpload::CUSTOMER_MAX_KB * 1024;

        $over = $this->postAvatar($store, $customer, $token, $this->upload('big.png', self::pngOf($ceiling + 1024)));

        $this->assertSame(0, (int) $over->json('status'), 'An avatar over the ceiling was accepted.');
        $this->assertStringContainsString((string) ThemeImageUpload::CUSTOMER_MAX_KB, (string) $over->json('message'), 'The refusal does not name the ceiling.');
        $this->assertSame([], Storage::disk('theme')->allFiles());

        $at = $this->postAvatar($store, $customer, $token, $this->upload('big.png', self::pngOf($ceiling)));

        $this->assertSame(1, (int) $at->json('status'), 'An avatar exactly at the ceiling was refused: ' . $at->getContent());
        $this->assertCount(1, Storage::disk('theme')->allFiles());
    }

    /**
     * THE ORDER: DECIDE, STORE, THEN CHARGE. The helper used to charge the plan's storage before it had looked at the file. The first
     * half is in the refusal cases above (the figure stays 0.0 for a refused file); this is the control that the figure MOVES for an
     * accepted one -- without it, "not charged" would be true of a counter that never counts.
     */
    public function test_an_accepted_avatar_is_charged_to_the_stores_owner(): void
    {
        [$store, $customer, $token, $merchant] = $this->selfRegisteredCustomer();
        $this->givePlan($merchant, 100);

        $this->postAvatar($store, $customer, $token, $this->upload('me.png', self::pngOf(300 * 1024)));

        $this->assertSame(0.29, $this->chargedStorage($merchant), 'A stored avatar of 300 KB is charged as 0.29 MB to the store\'s owner.');
    }

    /**
     * OVER THE PLAN'S STORAGE: NOTHING IS KEPT ON DISK, AND THE PREVIOUS AVATAR STAYS. On the unchanged code this refusal was taken on
     * trust by the handler: it unlinked the previous avatar and wrote '' over the column.
     */
    public function test_an_upload_refused_for_the_plans_storage_keeps_nothing_and_keeps_the_previous_avatar(): void
    {
        [$store, $customer, $token, $merchant] = $this->selfRegisteredCustomer();
        $this->givePlan($merchant, 1);
        $this->postAvatar($store, $customer, $token, $this->upload('me.png', (string) base64_decode(self::PNG)));
        $before = $this->avatarColumn($customer);
        $this->assertNotNull($before, 'Premise: the customer has an avatar to lose.');

        $merchant->storage_limit = 0.9;        // 0.9 used of 1 MB; a 300 KB image is 0.29 more
        $merchant->save();

        $response = $this->postAvatar($store, $customer, $token, $this->upload('bigger.png', self::pngOf(300 * 1024)));

        $this->assertSame(200, $response->status());
        $this->assertSame(0, (int) $response->json('status'), 'An upload over the plan\'s storage was reported as stored.');
        $this->assertStringContainsString('storage limit', (string) $response->json('message'));
        $this->assertSame($before, $this->avatarColumn($customer), 'An upload refused for the plan\'s storage cleared or replaced the previous avatar.');
        $this->assertSame([$before], Storage::disk('theme')->allFiles(), 'The refused file was left on disk, or the previous avatar is gone.');
        $this->assertSame(0.9, $this->chargedStorage($merchant), 'The plan was charged for a file that was not kept.');
    }

    /**
     * ON THE REAL DISK: a replaced avatar's previous file is unlinked, and it was in THIS store's folder. Avatars used to land loose
     * in `uploads/`, beside every other store's (`getCurrentStore()` reads the panel's guard and answers nothing for a customer);
     * they now carry their store in the path, which is what lets `StoredUpload`'s tenant rule apply to them at all.
     *
     * AND THE PLAN IS HANDED BACK WHAT THE OLD FILE COST. Each avatar is charged to the store's owner; the old one used to be
     * unlinked without a credit, so one visitor replacing a picture walked the merchant's used storage up to the plan's limit
     * (measured: 0.29, 0.58, 0.87 on a 1 MB plan, then every upload refused). After two uploads the figure is ONE picture's.
     */
    public function test_replacing_an_avatar_unlinks_the_previous_file_from_the_stores_own_folder(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('uploads/' . self::REAL_DISK_STORE_ID));
        $this->realDiskFolderIsOurs = true;          // only now may tearDown delete it
        Storage::forgetDisk('theme');                // the real disk, for this test alone

        [$merchant, $store] = $this->makeTenant();
        DB::table('stores')->where('id', $store->id)->update(['id' => self::REAL_DISK_STORE_ID]);
        (new Store)->flushCache();
        $merchant->current_store = self::REAL_DISK_STORE_ID;
        $merchant->save();
        $store = Store::findOrFail(self::REAL_DISK_STORE_ID);

        $this->looseFilesBefore = $this->looseFilesInUploads();
        $this->givePlan($merchant, 100);

        [, $customer, $token] = $this->selfRegisteredCustomer($merchant->fresh(), $store);

        $this->postAvatar($store, $customer, $token, $this->upload('first.png', self::pngOf(300 * 1024)));
        $first = (string) $this->avatarColumn($customer);
        $this->assertServerChosenPath($first, self::REAL_DISK_STORE_ID, 'png');
        $this->assertFileExists(base_path($first), 'Premise: the first avatar is on the real disk.');
        $this->assertSame(0.29, $this->chargedStorage($merchant), 'Premise: the first avatar was charged to the store\'s owner.');

        $this->postAvatar($store, $customer, $token, $this->upload('second.jpg', (string) base64_decode(self::JPEG) . str_repeat("\0", 200 * 1024)));
        $second = (string) $this->avatarColumn($customer);

        $this->assertNotSame($first, $second, 'Premise: the avatar was replaced.');
        $this->assertServerChosenPath($second, self::REAL_DISK_STORE_ID, 'jpg');
        $this->assertFileExists(base_path($second));
        $this->assertFileDoesNotExist(base_path($first), 'The replaced avatar was left on disk.');
        $this->assertEqualsWithDelta(0.2, $this->chargedStorage($merchant), 0.001,
            'The plan still carries the replaced avatar: charged 0.29 for the first and 0.20 for the second, it must hold 0.20 once the first is gone.');
        $this->assertSame($this->looseFilesBefore, $this->looseFilesInUploads(), 'An avatar was written loose into uploads/, outside any store\'s folder.');
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the panel's callers: the same helper, a merchant's hands
    // ---------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: string}> the admin API route, its file field, its enabling field */
    public static function adminApiImageHandlers(): array
    {
        return [
            'cash on delivery' => ['cod-payment', 'cod_image', 'is_cod_enabled'],
            'bank transfer' => ['bank-payment', 'bank_transfer_image', 'is_bank_transfer_enabled'],
            'stripe' => ['stripe-payment', 'stripe_image', 'is_stripe_enabled'],
        ];
    }

    /**
     * The three admin-API payment handlers. An ACCEPTED image is asserted on the disk and not on the response: these handlers
     * persist with `insert ... ON DUPLICATE KEY UPDATE`, which this suite's SQLite cannot run, so an allowed write throws AFTER
     * the upload (AdminApiSettingsSecretsTest records the same). The accepted image comes FIRST and is the premise for the
     * refusal after it: this request, with this fixture, does reach the helper.
     */
    #[DataProvider('adminApiImageHandlers')]
    public function test_an_admin_api_payment_image_is_an_image_or_it_is_not_stored(string $uri, string $fileField, string $enabledField): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Setting');
        $headers = ['Authorization' => 'Bearer ' . $merchant->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $post = fn (UploadedFile $file) => tap($this->post('/api/admin/' . $uri, [
            'store_id' => $store->id, 'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD', $enabledField => 'on', $fileField => $file,
        ], $headers), fn () => $this->app['auth']->forgetGuards());

        $post($this->upload('logo.png', (string) base64_decode(self::PNG)));

        $stored = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $stored, "Premise: {$uri} stores an image it is sent, so the refusals below are refusals.");
        $this->assertServerChosenPath($stored[0], (int) $store->id, 'png');

        foreach ([['shell.php', self::PHP], ['page.html', self::HTML], ['photo.html', (string) base64_decode(self::PNG)], ['.htaccess', "AddType application/x-httpd-php .png\n"]] as [$name, $bytes]) {
            $refused = $post($this->upload($name, $bytes));

            $this->assertSame($stored, Storage::disk('theme')->allFiles(), "{$uri} stored a file named {$name}.");
            $this->assertSame(200, $refused->status(), "{$uri} did not answer a refused {$name} with the error envelope.");
            $this->assertSame(0, (int) $refused->json('status'));
        }

        // SVG is still accepted from the panel -- owner decision D-29.5, see App\Services\ThemeImageUpload -- and it is stored as what it is.
        $post($this->upload('logo.svg', self::SVG));

        $svg = array_values(array_diff(Storage::disk('theme')->allFiles(), $stored));
        $this->assertCount(1, $svg, "{$uri} no longer accepts an SVG gateway logo.");
        $this->assertServerChosenPath($svg[0], (int) $store->id, 'svg');
    }

    /**
     * The web payment settings: one form and one save for every gateway. A refused image used to vanish -- the private method
     * returned a redirect its caller threw away, so the merchant read "Setting successfully updated." over an image that had not
     * changed. It is now reported by gateway, and the rest of the form is still saved.
     */
    public function test_the_web_payment_settings_report_a_refused_gateway_image_and_store_an_accepted_one(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Store Setting');
        $merchant = User::find($merchant->id);
        $row = fn (string $name) => DB::table('settings')->where('store_id', $store->id)->where('name', $name)->value('value');

        $refused = $this->actingAs($merchant)->post('/payment-setting', [
            'is_cod_enabled' => 'on', 'cod_info' => 'Pay the courier', 'cod_image' => $this->upload('shell.php', self::PHP),
        ]);

        $this->assertSame([], Storage::disk('theme')->allFiles(), 'The web payment settings stored a PHP file as a gateway image.');
        $refused->assertSessionHas('error');
        $this->assertStringContainsString('Cash on delivery (', (string) session('error'), 'The refusal does not say which gateway\'s image it was.');
        $this->assertStringContainsString('must be a file of type: jpg, jpeg, png, gif, webp, svg', (string) session('error'));
        $refused->assertSessionMissing('success');
        $this->assertNull($row('cod_image'), 'A refused image wrote a cod_image row.');
        $this->assertSame('on', $row('is_cod_enabled'), 'One refused image must not abort the rest of the save.');
        $this->assertSame('Pay the courier', $row('cod_info'));

        $accepted = $this->actingAs($merchant)->post('/payment-setting', [
            'is_cod_enabled' => 'on', 'cod_info' => 'Pay the courier', 'cod_image' => $this->upload('logo.png', (string) base64_decode(self::PNG)),
        ]);

        $accepted->assertSessionHas('success');
        $stored = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertServerChosenPath($stored[0], (int) $store->id, 'png');
        $this->assertSame($stored[0], $row('cod_image'));
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the helper itself: what holds for ANY caller, the two vendored ones included
    // ---------------------------------------------------------------------------------------------------------------------------

    /** Called the way the vendored sign-in handlers call it: one argument, a panel session. */
    public function test_the_helper_refuses_what_is_not_an_image_for_any_caller(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->actingAs($merchant);

        foreach ([
            ['shell.php', self::PHP], ['shell.phtml', self::PHP], ['page.html', self::HTML], ['photo.html', (string) base64_decode(self::PNG)],
            ['photo.php', (string) base64_decode(self::PNG)], ['photo.png', self::PHP], ['.htaccess', "AddType application/x-httpd-php .png\n"],
        ] as [$name, $bytes]) {
            $result = upload_theme_image($this->upload($name, $bytes));

            $this->assertFalse($result['status'], "The helper stored a file named {$name}.");
            $this->assertSame('', $result['image_path']);
            $this->assertStringContainsString('must be a file of type', $result['message']);
        }

        $this->assertFalse(upload_theme_image('uploads/logo/logo-dark.png')['status'], 'A string in the file\'s place was accepted.');
        $this->assertSame([], Storage::disk('theme')->allFiles());

        $png = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)));
        $this->assertTrue($png['status']);
        $this->assertServerChosenPath($png['image_path'], (int) $store->id, 'png');

        $svg = upload_theme_image($this->upload('logo.svg', self::SVG));
        $this->assertTrue($svg['status'], 'The panel no longer accepts an SVG: ' . $svg['message']);
        $this->assertServerChosenPath($svg['image_path'], (int) $store->id, 'svg');

        $this->assertFalse(upload_theme_image($this->upload('logo.svg', self::SVG), 0, 0, $store->id, true)['status'],
            'A customer upload accepted an SVG.');
    }

    /** `$key` goes into the stored name. No caller passes a client's value today; none can make it a path tomorrow. */
    public function test_the_name_prefix_cannot_carry_a_path(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->actingAs($merchant);

        $result = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)), '../../public/x.php%00');

        $this->assertTrue($result['status']);
        $this->assertServerChosenPath($result['image_path'], (int) $store->id, 'png', 'publicxphp00');
        $this->assertSame([$result['image_path']], Storage::disk('theme')->allFiles());
    }

    /** With no panel session and no store named, the folder is `uploads` -- and the stored value carries no doubled slash. */
    public function test_with_no_store_the_path_is_still_a_clean_one(): void
    {
        $result = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)));

        $this->assertTrue($result['status']);
        $this->assertMatchesRegularExpression('#^uploads/0\d{8}_\d{10}_[0-9a-f]{12}\.png$#', $result['image_path']);
    }

    /**
     * A WRITE THAT FAILS IS A FAILURE, AND IS NOT CHARGED. The `theme` disk is configured `throw => false`, so a failed write is a
     * `false` return -- which the helper used to report as success, with `image_path => false`, after charging the plan.
     */
    public function test_a_failed_write_is_reported_and_charges_nothing(): void
    {
        [$merchant] = $this->makeTenant();
        $this->givePlan($merchant, 100);
        Storage::set('theme', new class {
            public function putFileAs(...$arguments)
            {
                return false;
            }
        });

        $result = upload_theme_image($this->upload('logo.png', self::pngOf(300 * 1024)), 0, $merchant->id);

        $this->assertFalse($result['status'], 'A write that failed was reported as a stored image.');
        $this->assertSame('', $result['image_path']);
        $this->assertSame(0.0, $this->chargedStorage($merchant), 'The plan was charged for an image that was never written.');
    }

    /**
     * A REFUSAL LEAVES A LINE FOR WHOEVER ASKS, AFTERWARDS, WHETHER ANYBODY TRIED -- and the line holds what was refused, not whose
     * it was: the extension the client named, the type the bytes are, the size, the store. Never the file's name, which is a
     * stranger's text and may be a person's.
     */
    public function test_a_refusal_is_logged_and_the_log_does_not_carry_the_clients_file_name(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logged) {
            if ($event->message === 'an image upload was refused') {
                $logged[] = $event;
            }
        });

        $this->postAvatar($store, $customer, $token, $this->upload('me.png', (string) base64_decode(self::PNG)));
        $this->assertSame([], $logged, 'An accepted image was logged as a refusal.');

        $this->postAvatar($store, $customer, $token, $this->upload('Jane Roe passport.PHP', self::PHP));

        $this->assertCount(1, $logged, 'A refused upload left no line in the log.');
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame(
            ['sent' => 'file', 'client_extension' => 'PHP', 'sniffed_type' => 'text/x-php', 'kilobytes' => 1, 'customer_upload' => true, 'store' => (int) $store->id],
            $logged[0]->context
        );
        $this->assertStringNotContainsStringIgnoringCase('jane', (string) json_encode($logged[0]->context), 'The log carries the client\'s file name.');

        $this->postAvatar($store, $customer, $token, 'uploads/logo/logo-dark.png');

        $this->assertCount(2, $logged);
        $this->assertSame(['sent' => 'string', 'customer_upload' => true, 'store' => (int) $store->id], $logged[1]->context);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the two locks, each on its own
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * THE STORED EXTENSION IS READ FROM THE BYTES. Held apart from the validation on purpose: the refusal cases above pass if
     * EITHER lock holds, so this is the one place that fails when the name on disk goes back to being the client's.
     */
    public function test_the_stored_extension_is_decided_by_the_bytes_alone(): void
    {
        $png = (string) base64_decode(self::PNG);

        $this->assertSame('png', ThemeImageUpload::storedExtension($this->upload('photo.php', $png), true));
        $this->assertSame('png', ThemeImageUpload::storedExtension($this->upload('photo.html', $png), true));
        $this->assertSame('jpg', ThemeImageUpload::storedExtension($this->upload('photo.png', (string) base64_decode(self::JPEG)), true));
        $this->assertNull(ThemeImageUpload::storedExtension($this->upload('photo.png', self::PHP), true), 'PHP bytes were given an image extension.');
        $this->assertNull(ThemeImageUpload::storedExtension($this->upload('photo.png', self::HTML), false));
        $this->assertNull(ThemeImageUpload::storedExtension($this->upload('logo.svg', self::SVG), true), 'A customer upload may be stored as SVG.');
        $this->assertSame('svg', ThemeImageUpload::storedExtension($this->upload('logo.png', self::SVG), false));
        $this->assertNull(ThemeImageUpload::storedExtension('uploads/logo/logo-dark.png', false));
    }

    public function test_the_two_lists_differ_by_svg_and_by_nothing_else(): void
    {
        $this->assertSame(['svg'], array_values(array_diff(ThemeImageUpload::PANEL_TYPES, ThemeImageUpload::CUSTOMER_TYPES)));
        $this->assertSame([], array_values(array_diff(ThemeImageUpload::CUSTOMER_TYPES, ThemeImageUpload::PANEL_TYPES)));
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], ThemeImageUpload::CUSTOMER_TYPES);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // census
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * Calls, not mentions: a PHP file is read as TOKENS, so a comment or a string that names the helper is not a call and the
     * definition itself is not one either. A Blade file is not PHP to the tokenizer, so it is read as text.
     */
    private static function callsOfTheHelperIn(string $source, bool $blade): int
    {
        if ($blade) {
            return (int) preg_match_all('/(?<![A-Za-z0-9_>:$])upload_theme_image\s*\(/', $source);
        }

        $calls = 0;
        $previous = null;

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && strtolower(ltrim($token[1], '\\')) === 'upload_theme_image'
                && ! (is_array($previous) && $previous[0] === T_FUNCTION)) {
                $calls++;
            }

            $previous = $token;
        }

        return $calls;
    }

    /**
     * WHO CALLS THE HELPER. The rule lives in the helper because its callers are seven and two of them are vendored; this is the list
     * that claim rests on. A new caller fails here so that somebody decides whether it is a panel upload or a customer's -- the fifth
     * argument is the only thing that keeps SVG away from a stranger.
     */
    public function test_every_caller_of_the_helper_is_known(): void
    {
        $found = [];

        foreach (['app', 'packages', 'routes', 'resources/views', 'themes', 'config', 'bootstrap', 'database'] as $root) {
            if (! is_dir(base_path($root))) {
                continue;
            }

            // A plain directory walk: `File::allFiles()` builds and SORTS an object for every file in the tree, images included.
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (! str_contains($source, 'upload_theme_image')) {
                    continue;        // nothing to tokenize
                }

                $calls = self::callsOfTheHelperIn($source, str_ends_with($file->getFilename(), '.blade.php'));

                if ($calls > 0) {
                    $found[str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname())] = $calls;
                }
            }
        }

        ksort($found);

        $this->assertSame([
            'app/Http/Controllers/Api/ApiController.php' => 1,
            'app/Http/Controllers/Api/DashboardController.php' => 3,
            'app/Http/Controllers/PaymentSettingController.php' => 1,
            'packages/workdo/SignInWithFacebook/src/app/Http/Controllers/SignInWithFacebookController.php' => 1,
            'packages/workdo/SignInWithLinkedIn/src/app/Http/Controllers/SignInWithLinkedInController.php' => 1,
        ], $found, 'The callers of upload_theme_image() changed. Decide for the new one whether it is a customer\'s upload (the fifth argument).');

        $avatar = (string) file_get_contents(base_path('app/Http/Controllers/Api/ApiController.php'));
        $this->assertSame(1, substr_count($avatar, 'upload_theme_image($theme_image, 0, $store->created_by, $store->id, true)'),
            'The customer\'s avatar is the one caller that must say it is a customer\'s upload, in the slug\'s store.');
    }
}
