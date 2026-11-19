<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\PaymentSettingController;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\ThemeImageUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * group's 120-a-minute throttle and `APILog`, a logger, and nothing else) and answers with a Bearer token; the avatar handler asked `'image' =>
 * 'required'` and nothing more; and its response RETURNS THE STORED PATH. So an anonymous visitor could put a file of any type,
 * under the extension of their choice, at a known path below the document root. Whether a server then runs or renders it is the
 * server's configuration and is not in this repository; what this file pins is that the file is never stored.
 *
 * MEASURED ON THE UNCHANGED CODE, before any fix existed (the first form of this file, run against the parent commit's helper and
 * callers with the new class file present so that the three cases that read it could run): every one of its eleven refusal cases
 * stored its file (`shell.php` as `.php`, `page.html` as `.html`, `.htaccess` as `<name>.htaccess`, ...), the avatar handler
 * answered 500 to a string, and an upload refused for the plan's storage erased the avatar it was meant to replace.
 *
 * EVERY CASE DRIVES A REAL FILE. `UploadedFile::fake()` reports its type from its NAME, so a rule that sniffs the bytes would be
 * judged by the name and the case would pass or fail for the wrong reason; these are `UploadedFile` objects over real bytes on
 * disk, and the image fixtures really decode (a test says so: the first PNG here had a bad checksum and no library could open
 * it, which the rule never noticed because it reads a signature).
 *
 * WHAT THE FAKED DISK CANNOT SHOW, AND WHERE IT IS SHOWN INSTEAD. The `theme` disk is faked for every test but one. That one
 * writes to the real disk, in a store folder no real store has, and removes what it was answered with -- and against the code this
 * file was written to fail on, its avatars land LOOSE in the tracked `uploads/` (the first such run left a PNG there, which is
 * why the teardown also removes those paths). The handler unlinks and credits a previous avatar through
 * `StoredUpload`, which resolves REAL paths: on the faked disk that step finds nothing and does nothing. So a faked test can show
 * that a refusal leaves the COLUMN alone and stores no new file -- and nothing about whether the previous FILE or the plan's
 * figure survived. Those two are asserted in the one real-disk test, in a store re-keyed to an id no real folder has.
 *
 * THE IDS ARE MADE TO DIFFER. Each test runs in its own transaction on an empty database, so the first user, the first store
 * and the first customer would all be id 1 and no assertion could tell a store's folder from a user's, or the owner who is
 * credited from the customer who uploads. setUp() builds decoys first; every fixture asserts the three ids are distinct.
 */
class ThemeImageUploadTypeTest extends SecurityTestCase
{
    use RefreshDatabase;

    // Real images, each 2x2 and written by GD: all four decode (test_the_image_fixtures_really_are_images).
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';
    private const JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4Ciiivnj9hP/2Q==';
    private const GIF = 'R0lGODdhAgACAIAAAMweHAAAACwAAAAAAgACAAACAoRRADs=';
    private const WEBP = 'UklGRjoAAABXRUJQVlA4IC4AAACwAQCdASoCAAIAAUAmJaACdLoABDAAAP7x3I/4DdfFtMv/vYL/3YL/3YL/WwAA';
    private const PHP = "<?php echo 'ss-probe'; ?>\n";
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>document.title="ss-probe"</script></svg>';
    private const HTML = "<!doctype html><html><body><script>document.title='ss-probe'</script></body></html>\n";

    /** `uploads/` is tracked: the one real-disk test uses a store on this id, which no real folder has (986xxx and 987xxx are taken). */
    private const REAL_DISK_STORE_ID = 985001;

    /** What a name this helper chose looks like, without its folder. */
    private const SERVER_NAME = '\d{8}_\d{10}_[0-9a-f]{12}';

    /** @var list<string> temporary files to remove */
    private array $temporary = [];

    private bool $realDiskFolderIsOurs = false;

    /** @var list<string> every path the real-disk test was answered with: those, and nothing else in `uploads/`, are its to remove */
    private array $answeredOnTheRealDisk = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('theme');
        $this->makeTheIdsDiffer();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        // Only what this test itself was told it had stored -- never "whatever is new in uploads/", which on a shared checkout
        // could be another process's file. Against the code this file was written to fail on, an avatar lands LOOSE in the
        // tracked `uploads/`; its path is in this list like any other.
        $uploads = realpath(base_path('uploads'));
        foreach ($this->answeredOnTheRealDisk as $relative) {
            $real = realpath(base_path($relative));
            if ($uploads !== false && $real !== false && is_file($real) && str_starts_with($real, $uploads . DIRECTORY_SEPARATOR)) {
                @unlink($real);
            }
        }

        if ($this->realDiskFolderIsOurs) {
            File::deleteDirectory(base_path('uploads/' . self::REAL_DISK_STORE_ID));
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * Two users with no store, a tenant, four customers: after this the next merchant is user 4, the next store is 2 and the
     * next customer is 5. None of them is used again.
     */
    private function makeTheIdsDiffer(): void
    {
        $this->makeMerchant();
        $this->makeMerchant();
        [, $decoyStore] = $this->makeTenant();

        foreach (range(1, 4) as $n) {
            Customer::forceCreate([
                'first_name' => 'Decoy', 'last_name' => (string) $n,
                'email' => 'decoy-' . $n . '-' . $this->uniqueFixtureRef() . '@example.test',
                'mobile' => '0900000' . $n, 'password' => bcrypt('not-a-real-password'),
                'store_id' => $decoyStore->id, 'status' => 1, 'register_type' => 'email',
            ]);
        }
    }

    /** A tenant whose owner's id is not its store's id: without that, `uploads/<user id>/` would pass for `uploads/<store id>/`. */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->assertNotSame((int) $merchant->id, (int) $store->id, 'Premise: the merchant\'s id is not the store\'s id.');

        return [$merchant, $store];
    }

    /** An upload as PHP hands it to the framework: real bytes on disk, the client's own name, the content type the client claims. */
    private function upload(string $clientName, string $bytes, ?string $claimedType = null): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ssup');
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new UploadedFile($path, $clientName, $claimedType, null, true);
    }

    /** A real PNG padded to a size. The type is read from the head of the file, so the padding does not change it. */
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
            [$merchant, $store] = $this->tenant();
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
        $this->assertCount(3, array_unique([(int) $merchant->id, (int) $store->id, (int) $customer->id]),
            'Premise: the owner, the store and the customer have three different ids, or no assertion below can tell them apart.');
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
            '#^uploads/' . $storeId . '/' . preg_quote($key, '#') . self::SERVER_NAME . '\.' . $extension . '$#',
            $path,
            'The stored path is not the server-chosen name in the store\'s own folder.'
        );
    }

    /** @return list<MessageLogged> the refusal lines logged from here on */
    private function &refusalsLogged(): array
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($event) use (&$logged) {
            if ($event->message === 'an image upload was refused') {
                $logged[] = $event;
            }
        });

        return $logged;
    }

    public function test_the_image_fixtures_really_are_images(): void
    {
        foreach (['PNG' => IMAGETYPE_PNG, 'JPEG' => IMAGETYPE_JPEG, 'GIF' => IMAGETYPE_GIF, 'WEBP' => IMAGETYPE_WEBP] as $name => $type) {
            $bytes = (string) base64_decode(constant('self::' . $name), true);
            $size = getimagesizefromstring($bytes);

            $this->assertIsArray($size, "The {$name} fixture is not an image.");
            $this->assertSame([2, 2, $type], [$size[0], $size[1], $size[2]], "The {$name} fixture is not the 2x2 {$name} it is used as.");

            if (function_exists('imagecreatefromstring')) {
                $this->assertNotFalse(@imagecreatefromstring($bytes), "The {$name} fixture carries an image signature and does not decode.");
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the storefront avatar: what a visitor who has only registered can and cannot store
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * Three things each refuse on their own, and a case can be refused by one, two or all three. The comment on each group says
     * which, because a case that would be refused anyway says nothing about the lock it seems to be about:
     *   B  the bytes: `mimes:` (the sniffed type is not in the list), and with it the stored-extension lock, which asks the same
     *   N  the client's name: `extensions:`
     *   P  the framework's own block of eight PHP extensions, which lives inside `mimes:`
     *
     * @return array<string, array{0: string, 1: string, 2?: string}> the client's file name, the bytes, the content type the client claims
     */
    public static function filesACustomerMayNotStore(): array
    {
        $png = (string) base64_decode(self::PNG);

        return [
            // B, N and P at once
            'a PHP file' => ['shell.php', self::PHP],
            'a PHP file under an older extension' => ['shell.phtml', self::PHP],
            'a PHP archive extension' => ['shell.phar', self::PHP],
            // B and N
            'an HTML page' => ['page.html', self::HTML],
            'a script' => ['code.js', "document.title='ss-probe';\n"],
            'a per-directory server override' => ['.htaccess', "AddType application/x-httpd-php .png\n"],
            'an SVG carrying a script' => ['logo.svg', self::SVG],
            'an HTML page under an SVG name' => ['page.svg', self::HTML],
            // B alone: the name is an image's, the bytes are not
            'PHP bytes under an image name' => ['photo.png', self::PHP],
            'PHP bytes behind a double extension' => ['shell.php.jpg', self::PHP],
            'an HTML page under an image name' => ['page.png', self::HTML],
            'SVG bytes under a raster name' => ['logo.png', self::SVG],
            // N alone: the bytes are an image, the name is not an image's. The four that say something about `extensions:`.
            'image bytes under an HTML name' => ['photo.html', $png],
            'image bytes with no extension at all' => ['photo', $png],
            'image bytes under an SVG name' => ['photo.svg', $png],
            'image bytes under a name that ends in a dot' => ['photo.png.', $png],
            // P and N: the bytes pass, and the framework refuses the name before `extensions:` is asked
            'image bytes under a PHP name' => ['photo.php', $png],
            'image bytes under an uppercase PHP name' => ['PHOTO.PHP', $png],
            // Nothing in the rule reads the content type a client CLAIMS. These two are their unclaimed twins with a claim added,
            // kept so that a rule which one day trusts the claim has something to fail.
            'a PHP file that claims to be an image' => ['shell.php', self::PHP, 'image/png'],
            'PHP bytes that claim to be an image, under an image name' => ['photo.jpg', self::PHP, 'image/jpeg'],
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
        $this->assertMatchesRegularExpression(
            '/^The (image file must be a file of type: jpg, jpeg, png, gif, webp|file name must end in one of: jpg, jpeg, png, gif, webp, jpe, jfif)\.$/',
            (string) $response->json('message'),
            'The refusal does not say what is accepted -- or the file was refused for some other reason than its type or its name.'
        );
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
            // two names a JPEG is commonly saved under: accepted on the name, never written to disk
            'a JPEG named .jfif, as Chrome on Windows saves one' => ['download.jfif', self::JPEG, 'jpg'],
            'a JPEG named .jpe' => ['scan.jpe', self::JPEG, 'jpg'],
            // THE EXTENSION IS THE SERVER'S: an image under another image's name is accepted -- as what it IS
            'a JPEG named .png' => ['holiday.png', self::JPEG, 'jpg'],
            'a PNG named .gif' => ['holiday.gif', self::PNG, 'png'],
            // and nothing of the client's name reaches the disk, whatever it spells
            'a PNG whose name carries a PHP extension inside it' => ['shell.php.png', self::PNG, 'png'],
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
     * A KNOWN LIMIT, PINNED SO THAT NOBODY READS THE RULE AS MORE THAN IT IS. The type is read from the file's signature; the
     * file is not decoded. A file that only BEGINS as a GIF is "a GIF" to this rule and is stored -- under the server's name
     * and a `.gif` extension, which is the whole of what the rule promises. It is inert unless a server hands a `.gif` to an
     * interpreter. Re-encoding the image would remove the tail; that is a product decision and is not made here. If this case
     * ever fails because the content is checked, delete it and say so in ThemeImageUpload.
     */
    public function test_known_limit_a_file_that_only_begins_as_an_image_is_stored_under_an_image_name(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $bytes = "GIF89a\x02\x00\x02\x00\x80\x00\x00<?php echo 'ss-probe'; ?>";

        $response = $this->postAvatar($store, $customer, $token, $this->upload('me.gif', $bytes));

        $this->assertSame(1, (int) $response->json('status'), 'The rule now refuses a file for its content: see the comment on this test.');
        $stored = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertServerChosenPath($stored[0], (int) $store->id, 'gif');
        $this->assertStringContainsString('<?php', (string) Storage::disk('theme')->get($stored[0]), 'The content was changed on the way in.');
    }

    /**
     * A REFUSED REPLACEMENT DOES NOT TOUCH THE COLUMN AND STORES NOTHING. That is all a faked disk can show: whether the
     * previous FILE survived, and whether the plan was credited for it, is asserted on the real disk further down.
     */
    public function test_a_refused_replacement_leaves_the_avatar_column_alone_and_stores_nothing(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $this->postAvatar($store, $customer, $token, $this->upload('me.png', (string) base64_decode(self::PNG)));
        $before = $this->avatarColumn($customer);
        $this->assertNotNull($before, 'Premise: the customer has an avatar to lose.');

        $response = $this->postAvatar($store, $customer, $token, $this->upload('shell.php', self::PHP));

        $this->assertSame(0, (int) $response->json('status'));
        $this->assertSame($before, $this->avatarColumn($customer), 'A refused upload cleared or replaced the avatar column.');
        $this->assertSame([$before], Storage::disk('theme')->allFiles(), 'A refused upload stored a file.');
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
        $this->assertSame('The image file must be a file.', $response->json('message'), 'A value that is not a file is told it is the wrong TYPE of file.');
        $this->assertSame([], Storage::disk('theme')->allFiles());
        $this->assertNull($this->avatarColumn($customer));
    }

    /**
     * AN UPLOAD THAT FAILED IN PHP -- over `upload_max_filesize`, or cut short -- arrives as an upload object with no file behind
     * it. It is refused with a sentence, logged without a type or a size (there is nothing to read), and is not a crash: the old
     * helper answered `status => true` with `image_path => false` for it.
     */
    public function test_an_upload_that_php_itself_refused_is_reported_and_is_not_a_crash(): void
    {
        [$merchant] = $this->tenant();
        $this->actingAs($merchant);
        $logged = &$this->refusalsLogged();

        $result = upload_theme_image(new UploadedFile('', 'holiday.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, false));

        $this->assertFalse($result['status']);
        $this->assertSame('invalid', $result['reason']);
        $this->assertSame('The image file failed to upload.', $result['message']);
        $this->assertCount(1, $logged);
        $this->assertSame(['sent' => 'file', 'client_extension' => 'jpg', 'sniffed_type' => null, 'kilobytes' => null, 'customer_upload' => false, 'store' => (int) $merchant->current_store], $logged[0]->context);
        $this->assertSame([], Storage::disk('theme')->allFiles());
    }

    /**
     * The ceiling is 2048 KB, to the kilobyte: 2048 KB is stored and 2049 KB is not.
     *
     * WHERE IT IS REACHED. An `UploadedFile` built in test mode is not judged by PHP's `upload_max_filesize`, so this drives the
     * rule itself. On a host where that limit is 2M -- its default, and this machine's -- PHP refuses a larger upload first:
     * the handler's own `'image' => 'required'` answers "The image failed to upload." and the helper, so the sentence below and
     * the refusal log line, is not reached. The outcome is the same -- nothing stored -- and the mechanism is not.
     */
    public function test_an_avatar_over_the_size_ceiling_is_refused_and_one_at_the_ceiling_is_stored(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $ceiling = 2048 * 1024;

        $over = $this->postAvatar($store, $customer, $token, $this->upload('big.png', self::pngOf($ceiling + 1024)));

        $this->assertSame(0, (int) $over->json('status'), 'An avatar over the ceiling was accepted.');
        $this->assertSame('The image file must not be greater than 2048 kilobytes.', $over->json('message'));
        $this->assertSame([], Storage::disk('theme')->allFiles());

        $at = $this->postAvatar($store, $customer, $token, $this->upload('big.png', self::pngOf($ceiling)));

        $this->assertSame(1, (int) $at->json('status'), 'An avatar exactly at the ceiling was refused: ' . $at->getContent());
        $this->assertCount(1, Storage::disk('theme')->allFiles());
    }

    /**
     * A FILE THAT IS BOTH THE WRONG TYPE AND TOO LARGE IS TOLD ITS TYPE. With two rules failing the first sentence is the one shown,
     * and the type is the more important thing to say: it is the one that cannot be fixed by compressing. This is also the only
     * input on which removing the `mimes:` rule changes what a caller reads (the bytes are still refused, by the stored-extension
     * lock, with the same sentence in every other case) -- which is how a mutant that removes it stayed alive until it was asked.
     */
    public function test_a_file_of_the_wrong_type_and_over_the_ceiling_is_told_its_type(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();

        $response = $this->postAvatar($store, $customer, $token, $this->upload('big.png', self::HTML . str_repeat(' ', 2049 * 1024)));

        $this->assertSame(0, (int) $response->json('status'));
        $this->assertSame('The image file must be a file of type: jpg, jpeg, png, gif, webp.', $response->json('message'));
        $this->assertSame([], Storage::disk('theme')->allFiles());
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
     * OVER THE PLAN'S STORAGE: NOTHING NEW IS KEPT, THE COLUMN STAYS, AND THE SHOPPER IS NOT TOLD ABOUT THE MERCHANT'S PLAN. On the
     * unchanged code this refusal was taken on trust by the handler: it answered success, with an empty path, and wrote '' over the
     * column. (The previous FILE, and the figure after a credit, are asserted on the real disk.)
     */
    public function test_an_upload_refused_for_the_plans_storage_keeps_nothing_and_does_not_tell_the_shopper_why(): void
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
        $this->assertSame('The image could not be saved.', $response->json('message'),
            'A shopper was told the state of the merchant\'s plan -- or was told nothing at all.');
        $this->assertSame($before, $this->avatarColumn($customer), 'An upload refused for the plan\'s storage cleared or replaced the avatar column.');
        $this->assertSame([$before], Storage::disk('theme')->allFiles(), 'The refused file was left on disk.');
        $this->assertSame(0.9, $this->chargedStorage($merchant), 'The plan was charged for a file that was not kept.');
    }

    /**
     * ON THE REAL DISK, because the unlink and the credit of a previous avatar resolve real paths and do nothing on a faked one.
     *
     *   1. The first avatar creates the store's folder, and the folder is readable (0755): the framework would have made it 0700.
     *   2. A replacement unlinks the previous file and hands its size back to the plan: after two uploads the figure is ONE
     *      picture's. (Measured before the credit existed: 0.29, 0.58, 0.87 on a 1 MB plan, then every upload refused.)
     *   3. A replacement REFUSED FOR ITS TYPE touches nothing: the current file is still there, the column and the figure unchanged.
     *   4. So does one REFUSED FOR THE PLAN.
     *   5. And one whose SAVE FAILS: the new file is not kept and is not charged; the current avatar is still there.
     *
     * Avatars used to land loose in `uploads/`, beside every other store's; nothing here may.
     */
    public function test_on_the_real_disk_a_replacement_unlinks_and_credits_the_old_file_and_a_refused_one_touches_nothing(): void
    {
        $folder = base_path('uploads/' . self::REAL_DISK_STORE_ID);

        if (is_dir($folder)) {
            // What a killed run of this very test left behind, and nothing else, may be cleared away.
            $inside = array_values(array_diff(scandir($folder) ?: [], ['.', '..']));
            $ours = array_filter($inside, fn ($name) => preg_match('/^0' . self::SERVER_NAME . '\.(png|jpg|gif|webp)$/', $name) === 1);
            $this->assertCount(count($inside), $ours, "uploads/" . self::REAL_DISK_STORE_ID . " exists and holds something this test did not write; it is not this test's to remove.");
            File::deleteDirectory($folder);
        }
        $this->realDiskFolderIsOurs = true;          // only now may tearDown delete it
        Storage::forgetDisk('theme');                // the real disk, for this test alone

        [$merchant, $store] = $this->tenant();
        DB::table('stores')->where('id', $store->id)->update(['id' => self::REAL_DISK_STORE_ID]);
        (new Store)->flushCache();
        $merchant->current_store = self::REAL_DISK_STORE_ID;
        $merchant->save();
        $store = Store::findOrFail(self::REAL_DISK_STORE_ID);
        $this->givePlan($merchant, 100);
        $looseBefore = $this->looseFilesInUploads();

        [, $customer, $token] = $this->selfRegisteredCustomer($merchant->fresh(), $store);
        $post = function ($image) use ($store, $customer, $token) {
            $response = $this->postAvatar($store, $customer, $token, $image);
            if (is_string($response->json('message')) && str_starts_with((string) $response->json('message'), 'uploads/')) {
                $this->answeredOnTheRealDisk[] = (string) $response->json('message');
            }

            return $response;
        };

        // 1
        $post($this->upload('first.png', self::pngOf(300 * 1024)));
        $first = (string) $this->avatarColumn($customer);
        $this->assertServerChosenPath($first, self::REAL_DISK_STORE_ID, 'png');
        $this->assertFileExists(base_path($first), 'Premise: the first avatar is on the real disk.');
        $this->assertSame('0755', substr(sprintf('%o', fileperms($folder)), -4), 'The folder the helper created is not readable by another user.');
        $this->assertSame(0.29, $this->chargedStorage($merchant), 'Premise: the first avatar was charged to the store\'s owner.');

        // 2
        $post($this->upload('second.jpg', (string) base64_decode(self::JPEG) . str_repeat("\0", 200 * 1024)));
        $second = (string) $this->avatarColumn($customer);
        $this->assertNotSame($first, $second, 'Premise: the avatar was replaced.');
        $this->assertServerChosenPath($second, self::REAL_DISK_STORE_ID, 'jpg');
        $this->assertFileExists(base_path($second));
        $this->assertFileDoesNotExist(base_path($first), 'The replaced avatar was left on disk.');
        $this->assertEqualsWithDelta(0.2, $this->chargedStorage($merchant), 0.001,
            'The plan still carries the replaced avatar: charged 0.29 for the first and 0.20 for the second, it must hold 0.20 once the first is gone.');

        $untouched = function (string $after) use ($second, $customer, $merchant, $folder) {
            $this->assertFileExists(base_path($second), "The current avatar's file is gone {$after}.");
            $this->assertSame($second, $this->avatarColumn($customer), "The avatar column changed {$after}.");
            $this->assertEqualsWithDelta(0.2, $this->chargedStorage($merchant), 0.001, "The plan's figure moved {$after}.");
            $this->assertSame([basename($second)], array_values(array_diff(scandir($folder) ?: [], ['.', '..'])), "A file was left in the store's folder {$after}.");
        };

        // 3
        $this->assertSame(0, (int) $post($this->upload('shell.php', self::PHP))->json('status'));
        $untouched('after an upload refused for its type');

        // 4
        $merchant->storage_limit = 99.9;       // of a 100 MB plan: 300 KB more is over it
        $merchant->save();
        $this->assertSame('The image could not be saved.', $post($this->upload('third.png', self::pngOf(300 * 1024)))->json('message'));
        $merchant->storage_limit = 0.2;
        $merchant->save();
        $untouched('after an upload refused for the plan');

        // 5: the database refuses the customer's row, so the new path cannot be saved
        DB::statement("CREATE TRIGGER ss_sec72_no_avatar BEFORE UPDATE ON customers BEGIN SELECT RAISE(ABORT, 'ss-sec72: no update'); END");
        DB::enableQueryLog();
        $failed = $post($this->upload('fourth.png', self::pngOf(300 * 1024)));
        $charges = count(array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^update "users" set .*"storage_limit"/i', $query['query']) === 1));
        DB::disableQueryLog();
        DB::statement('DROP TRIGGER ss_sec72_no_avatar');
        $this->assertSame(500, $failed->status(), 'Premise: the save of the new path failed.');
        $this->assertSame(2, $charges, 'Premise: the request got as far as storing and charging the new file, and then handed the charge back -- two writes of the plan figure.');
        $untouched('after a replacement whose save failed');

        $this->assertSame($looseBefore, $this->looseFilesInUploads(), 'An avatar was written loose into uploads/, outside any store\'s folder.');
    }

    /** @return list<string> the names of the files sitting directly in `uploads/`, in no store's folder */
    private function looseFilesInUploads(): array
    {
        return array_values(array_filter(scandir(base_path('uploads')) ?: [], fn ($name) => is_file(base_path('uploads/' . $name))));
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
     * refusals after it: this request, with this fixture, does reach the helper.
     *
     * THE FOLDER IS THE STORE THE REQUEST NAMES, not the store the merchant's account points at: the merchant here owns two, the
     * account points at the first and the request names the second. The handler resolves and authorises the named one and
     * writes its settings row; the image used to go to the other one's folder.
     */
    #[DataProvider('adminApiImageHandlers')]
    public function test_an_admin_api_payment_image_is_an_image_or_it_is_not_stored(string $uri, string $fileField, string $enabledField): void
    {
        [$merchant, $accountStore] = $this->tenant();
        $named = $this->makeStore($merchant);
        $this->assertSame((int) $accountStore->id, (int) $merchant->fresh()->current_store, 'Premise: the account points at the merchant\'s FIRST store.');
        $this->assertCount(3, array_unique([(int) $merchant->id, (int) $accountStore->id, (int) $named->id]), 'Premise: three different ids.');
        $this->grantPermission($merchant, 'Manage Setting');
        $headers = ['Authorization' => 'Bearer ' . $merchant->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $post = fn (UploadedFile $file) => tap($this->post('/api/admin/' . $uri, [
            'store_id' => $named->id, 'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD', $enabledField => 'on', $fileField => $file,
        ], $headers), fn () => $this->app['auth']->forgetGuards());

        $post($this->upload('logo.png', (string) base64_decode(self::PNG)));

        $stored = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $stored, "Premise: {$uri} stores an image it is sent, so the refusals below are refusals.");
        $this->assertServerChosenPath($stored[0], (int) $named->id, 'png');

        foreach ([['shell.php', self::PHP], ['page.html', self::HTML], ['photo.html', (string) base64_decode(self::PNG)], ['.htaccess', "AddType application/x-httpd-php .png\n"]] as [$name, $bytes]) {
            $refused = $post($this->upload($name, $bytes));

            $this->assertSame($stored, Storage::disk('theme')->allFiles(), "{$uri} stored a file named {$name}.");
            $this->assertSame(200, $refused->status(), "{$uri} did not answer a refused {$name} with the error envelope.");
            $this->assertSame(0, (int) $refused->json('status'));
            $this->assertMatchesRegularExpression('/^The (image file must be a file of type|file name must end in one of): /', (string) $refused->json('message'),
                "{$uri} answers a refusal without the sentence in `message`.");
            $this->assertSame($refused->json('message'), $refused->json('data.message'), "{$uri} answers a refusal in a shape of its own.");
        }

        // SVG is still accepted from the panel -- owner decision D-29.5, see App\Services\ThemeImageUpload -- and it is stored as what it is.
        $post($this->upload('logo.svg', self::SVG));

        $svg = array_values(array_diff(Storage::disk('theme')->allFiles(), $stored));
        $this->assertCount(1, $svg, "{$uri} no longer accepts an SVG gateway logo.");
        $this->assertServerChosenPath($svg[0], (int) $named->id, 'svg');
    }

    /**
     * The web payment settings: one form and one save for every gateway. A refused image used to vanish -- the private method
     * returned a redirect its caller threw away -- and is now reported by the gateway's own NAME, beside any gateway left
     * switched off, with the rest of the form still saved.
     *
     * TWO MERCHANTS, ONE CONTROLLER OBJECT. A Route keeps the controller it resolved, so within one application instance the
     * second request is served by the object the first one used -- asserted below, because everything after it depends on it.
     * The controller keeps what it will WRITE on itself, so without the reset at the top of update() the second merchant's
     * save writes the first merchant's currency into their store and repeats the first merchant's two complaints.
     */
    public function test_the_web_payment_settings_report_a_refused_image_by_gateway_name_and_carry_nothing_into_the_next_save(): void
    {
        [$first, $firstStore] = $this->tenant();
        [$second, $secondStore] = $this->tenant();
        foreach ([$first, $second] as $merchant) {
            $this->grantPermission($merchant, 'Manage Store Setting');
        }
        $row = fn (Store $store, string $name) => DB::table('settings')->where('store_id', $store->id)->where('name', $name)->value('value');
        $controller = fn () => app('router')->getRoutes()->getByName('payment.settings')->getController();

        $refused = $this->actingAs(User::find($first->id))->post('/payment-setting', [
            'CURRENCY' => 'FIRST-MERCHANT-CURRENCY', 'CURRENCY_NAME' => 'FMC',
            'is_stripe_enabled' => 'on', 'stripe_publishable_key' => '', 'stripe_secret_key' => '',
            'is_bank_transfer_enabled' => 'on', 'bank_transfer_image' => $this->upload('shell.php', self::PHP),
        ]);
        $servedBy = spl_object_id($controller());

        $this->assertSame([], Storage::disk('theme')->allFiles(), 'The web payment settings stored a PHP file as a gateway image.');
        $refused->assertSessionMissing('success');
        $error = (string) session('error');
        $this->assertStringContainsString('Stripe (', $error, 'The gateway left switched off is not named by its name.');
        $this->assertStringContainsString('). Saved, but these images were not uploaded: Bank Transfer (The image file must be a file of type: jpg, jpeg, png, gif, webp, svg.)', $error,
            'The refused image is not reported by its gateway\'s name, or the two sentences run together.');
        $this->assertNull($row($firstStore, 'bank_transfer_image'), 'A refused image wrote a row.');
        $this->assertSame('on', $row($firstStore, 'is_bank_transfer_enabled'), 'One refused image must not abort the rest of the save.');
        $this->assertSame('FIRST-MERCHANT-CURRENCY', $row($firstStore, 'CURRENCY'));

        $this->app['auth']->forgetGuards();
        $accepted = $this->actingAs(User::find($second->id))->post('/payment-setting', [
            'is_cod_enabled' => 'on', 'cod_info' => 'Pay the courier', 'cod_image' => $this->upload('logo.png', (string) base64_decode(self::PNG)),
        ]);

        $this->assertSame($servedBy, spl_object_id($controller()), 'Premise: both requests were served by one controller object.');
        $accepted->assertSessionHas('success');
        $accepted->assertSessionMissing('error');
        $this->assertNull($row($secondStore, 'CURRENCY'), 'The first merchant\'s currency was written into the second merchant\'s store.');
        $stored = Storage::disk('theme')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertServerChosenPath($stored[0], (int) $secondStore->id, 'png');
        $this->assertSame($stored[0], $row($secondStore, 'cod_image'));
    }

    /**
     * EVERY GATEWAY HAS A NAME THAT IS A STRING. The two messages above used to print `__($id)`, and for the id `myfatoorah`
     * that is a translation GROUP -- an array -- so switching MyFatoorah on from the form with a required field empty saved,
     * then answered HTTP 500. The second half drives exactly that request.
     */
    public function test_every_gateway_is_named_by_its_name_and_none_of_them_breaks_the_message(): void
    {
        $controller = app(PaymentSettingController::class);
        $displayName = new \ReflectionMethod($controller, 'gatewayDisplayName');
        $displayName->setAccessible(true);
        $ids = array_column($controller->getPaymentGateways(), 'id');
        $this->assertGreaterThan(40, count($ids), 'Premise: the gateway list was read.');

        foreach ($ids as $id) {
            $name = $displayName->invoke($controller, $id);
            $this->assertIsString($name, "The gateway {$id} has no name that is a string.");
            $this->assertNotSame('', $name);
        }
        $this->assertSame(['COD', 'Bank Transfer', 'Stripe', 'MyFatoorah'], array_map(fn ($id) => $displayName->invoke($controller, $id), ['cod', 'bank_transfer', 'stripe', 'myfatoorah']));
        $this->assertIsArray(__('myfatoorah'), 'Premise: `myfatoorah` is a translation group, which is why an id must never be translated as a label.');

        // the definition translates its own name where it is built (`'name' => __('Bank Transfer')`): an Arabic merchant reads Arabic
        $locale = app()->getLocale();
        try {
            app()->setLocale('ar');
            $this->assertSame('تحويل مصرفي', $displayName->invoke($controller, 'bank_transfer'));
        } finally {
            app()->setLocale($locale);
        }

        [$merchant] = $this->tenant();
        $this->grantPermission($merchant, 'Manage Store Setting');
        $response = $this->actingAs(User::find($merchant->id))->post('/payment-setting', [
            'is_myfatoorah_enabled' => 'on', 'myfatoorah_pay_country_iso' => 'KWT', 'myfatoorah_mode' => 'sandbox', 'myfatoorah_pay_api_key' => '',
            'myfatoorah_image' => $this->upload('logo.bmp', 'BM' . str_repeat("\0", 64)),
        ]);

        $this->assertSame(302, $response->status(), 'Switching MyFatoorah on with a required field empty is a server error.');
        $error = (string) session('error');
        $this->assertStringContainsString('MyFatoorah (', $error);
        $this->assertStringContainsString('Saved, but these images were not uploaded: MyFatoorah (', $error);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the helper itself: what holds for ANY caller, the two vendored ones included
    // ---------------------------------------------------------------------------------------------------------------------------

    /** Called the way the vendored sign-in handlers call it: one argument, a panel session. */
    public function test_the_helper_refuses_what_is_not_an_image_for_any_caller(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->actingAs($merchant);

        foreach ([
            ['shell.php', self::PHP], ['shell.phtml', self::PHP], ['page.html', self::HTML], ['photo.html', (string) base64_decode(self::PNG)],
            ['photo.php', (string) base64_decode(self::PNG)], ['photo.png', self::PHP], ['.htaccess', "AddType application/x-httpd-php .png\n"],
        ] as [$name, $bytes]) {
            $result = upload_theme_image($this->upload($name, $bytes));

            $this->assertFalse($result['status'], "The helper stored a file named {$name}.");
            $this->assertSame('', $result['image_path']);
            $this->assertSame('invalid', $result['reason']);
            $this->assertMatchesRegularExpression('/^The (image file must be a file of type|file name must end in one of): /', $result['message']);
        }

        $this->assertFalse(upload_theme_image('uploads/logo/logo-dark.png')['status'], 'A string in the file\'s place was accepted.');
        $this->assertSame([], Storage::disk('theme')->allFiles());

        $png = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)));
        $this->assertTrue($png['status']);
        $this->assertSame('', $png['reason']);
        $this->assertServerChosenPath($png['image_path'], (int) $store->id, 'png');

        $svg = upload_theme_image($this->upload('logo.svg', self::SVG));
        $this->assertTrue($svg['status'], 'The panel no longer accepts an SVG: ' . $svg['message']);
        $this->assertServerChosenPath($svg['image_path'], (int) $store->id, 'svg');

        $this->assertFalse(upload_theme_image($this->upload('logo.svg', self::SVG), 0, 0, $store->id, true)['status'],
            'A customer upload accepted an SVG.');
    }

    /** A merchant's own SVG logo, as tracked in this repository, is still accepted from the panel. The shortest SVG is not the only one. */
    public function test_a_real_svg_logo_is_still_accepted_from_the_panel(): void
    {
        $logo = base_path('uploads/2/18_1788254389_logoStore.svg');
        $this->assertFileExists($logo, 'Premise: the tracked SVG logo this case reads is still in the repository.');
        [$merchant, $store] = $this->tenant();
        $this->actingAs($merchant);

        $result = upload_theme_image($this->upload('logoStore.svg', (string) file_get_contents($logo)));

        $this->assertTrue($result['status'], 'A real SVG logo is refused by the panel rule: ' . $result['message']);
        $this->assertServerChosenPath($result['image_path'], (int) $store->id, 'svg');
    }

    /**
     * THE RANDOM PART OF THE NAME IS WHAT KEEPS TWO UPLOADS APART. Twenty uploads in a loop take a few milliseconds, so they share a
     * second -- and the old `rand(0, 100)` let two of them share a name once in 101 and silently replaced the first. Twenty names
     * out of 101 would collide about nine times in ten; a constant collides every time; the real thing never does.
     */
    public function test_twenty_uploads_in_one_second_get_twenty_different_names(): void
    {
        [$merchant] = $this->tenant();
        $this->actingAs($merchant);

        $paths = [];
        foreach (range(1, 20) as $unused) {
            $result = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)));
            $this->assertTrue($result['status']);
            $paths[] = $result['image_path'];
        }

        $this->assertCount(20, array_unique($paths), 'Two uploads were given one name, and the second replaced the first.');
        $this->assertCount(20, Storage::disk('theme')->allFiles());
    }

    /** `$key` goes into the stored name. No caller passes a client's value today; none can make it a path tomorrow. */
    public function test_the_name_prefix_cannot_carry_a_path(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->actingAs($merchant);

        $result = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)), '../../public/x.php%00');

        $this->assertTrue($result['status']);
        $this->assertServerChosenPath($result['image_path'], (int) $store->id, 'png', 'publicxphp00');
        $this->assertSame([$result['image_path']], Storage::disk('theme')->allFiles());
    }

    /**
     * With no panel session and no store named, the folder is `uploads` itself -- the store-less place, recorded and not chosen --
     * and the stored value carries no doubled slash (the old one did, and `StoredPathPolicy` refused it for that).
     */
    public function test_with_no_store_the_path_is_still_a_clean_one(): void
    {
        $result = upload_theme_image($this->upload('logo.png', (string) base64_decode(self::PNG)));

        $this->assertTrue($result['status']);
        $this->assertMatchesRegularExpression('#^uploads/0' . self::SERVER_NAME . '\.png$#', $result['image_path']);
    }

    /**
     * A WRITE THAT FAILS IS A FAILURE, AND IS NOT CHARGED. The `theme` disk is configured `throw => false`, so a failed write is a
     * `false` return -- which the helper used to report as success, with `image_path => false`, after charging the plan.
     */
    public function test_a_failed_write_is_reported_and_charges_nothing(): void
    {
        [$merchant] = $this->tenant();
        $this->givePlan($merchant, 100);
        Storage::set('theme', new class {
            public function exists(...$arguments)
            {
                return true;
            }

            public function putFileAs(...$arguments)
            {
                return false;
            }
        });

        $result = upload_theme_image($this->upload('logo.png', self::pngOf(300 * 1024)), 0, $merchant->id);

        $this->assertFalse($result['status'], 'A write that failed was reported as a stored image.');
        $this->assertSame('write', $result['reason']);
        $this->assertSame('', $result['image_path']);
        $this->assertSame(0.0, $this->chargedStorage($merchant), 'The plan was charged for an image that was never written.');
    }

    /** If the charge itself fails after the file is written, the file is not left behind with nobody charged for it and no row naming it. */
    public function test_a_charge_that_throws_does_not_leave_the_file_behind(): void
    {
        [$merchant] = $this->tenant();
        $this->givePlan($merchant, 100);
        DB::statement("CREATE TRIGGER ss_sec72_no_charge BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'ss-sec72: no update'); END");

        try {
            upload_theme_image($this->upload('logo.png', self::pngOf(300 * 1024)), 0, $merchant->id);
            $this->fail('Premise: the charge throws.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('ss-sec72', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER ss_sec72_no_charge');
        }

        $this->assertSame([], Storage::disk('theme')->allFiles(), 'The file was left on disk, uncharged and named by no row.');
    }

    /**
     * A REFUSAL LEAVES A LINE FOR WHOEVER ASKS, AFTERWARDS, WHETHER ANYBODY TRIED -- and the line holds what was refused, not whose
     * it was. NO CHARACTER OF IT IS THE CLIENT'S: the extension is reported as a word from a fixed list, `none` or `other`, because
     * an "extension" is whatever follows the last dot of a name a stranger typed.
     */
    public function test_a_refusal_is_logged_and_nothing_the_client_typed_is_in_the_log(): void
    {
        [$store, $customer, $token] = $this->selfRegisteredCustomer();
        $logged = &$this->refusalsLogged();
        $context = function (int $n) use (&$logged) {      // by reference: an arrow function would hold the empty list it was made with
            return $logged[$n]->context;
        };
        $base = ['customer_upload' => true, 'store' => (int) $store->id];

        $this->postAvatar($store, $customer, $token, $this->upload('me.png', (string) base64_decode(self::PNG)));
        $this->assertSame([], $logged, 'An accepted image was logged as a refusal.');

        $this->postAvatar($store, $customer, $token, $this->upload('Jane Roe passport.PHP', self::PHP));
        $this->assertCount(1, $logged, 'A refused upload left no line in the log.');
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame(['sent' => 'file', 'client_extension' => 'php', 'sniffed_type' => 'text/x-php', 'kilobytes' => 1] + $base, $context(0));

        // the "extension" is a person's name; then a forged log line; then nothing at all
        $this->postAvatar($store, $customer, $token, $this->upload('scan.JaneRoe1985', self::PHP));
        $this->postAvatar($store, $customer, $token, $this->upload("x.php\n[2026-01-01] production.ALERT: forged", self::PHP));
        $this->postAvatar($store, $customer, $token, $this->upload('passport', self::PHP));
        $this->assertSame(['other', 'other', 'none'], [$context(1)['client_extension'], $context(2)['client_extension'], $context(3)['client_extension']]);
        $this->assertDoesNotMatchRegularExpression('/jane|forged|passport|ALERT/i', (string) json_encode(array_map($context, [0, 1, 2, 3])), 'The log carries text the client typed.');

        $this->postAvatar($store, $customer, $token, 'uploads/logo/logo-dark.png');
        $this->assertSame(['sent' => 'string'] + $base, $context(4));
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the rule's own parts
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * THE STORED EXTENSION IS READ FROM THE BYTES, AND DRAWN FROM THE LIST. Held apart from the validation on purpose: most of the
     * refusal cases above are refused by more than one rule, so this is where the stored-extension lock is asked on its own --
     * its own answer, not the helper's use of it. That the helper actually USES it for the name on disk is what the acceptance
     * cases show ("a JPEG named .png" must be stored as `.jpg`).
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
        $this->assertNull(ThemeImageUpload::storedExtension(new UploadedFile('', 'holiday.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, false), false));
    }

    /** The lists, the ceilings and the picker's list, as literals: a rule that follows its own constants proves nothing about their values. */
    public function test_the_lists_and_the_ceilings_are_what_the_documents_say(): void
    {
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], ThemeImageUpload::CUSTOMER_TYPES);
        $this->assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], ThemeImageUpload::PANEL_TYPES);
        $this->assertSame(['jpe', 'jfif'], ThemeImageUpload::JPEG_NAME_ALIASES);
        $this->assertSame(2048, ThemeImageUpload::CUSTOMER_MAX_KB);
        $this->assertSame(2048, ThemeImageUpload::maxKb(true));
        $this->assertSame(2048000, ThemeImageUpload::maxKb(false));
        $this->assertSame('.jpg,.jpeg,.png,.gif,.webp,.svg,.jpe,.jfif', ThemeImageUpload::acceptAttribute(false));
        $this->assertSame('.jpg,.jpeg,.png,.gif,.webp,.jpe,.jfif', ThemeImageUpload::acceptAttribute(true));
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // census
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * What a PHP source does with the helper's name, as tokens: CALLS (a comment that names the helper is not one, and the
     * definition is not one) and, separately, every OTHER way the name can appear in code -- as a string (`call_user_func(
     * 'upload_theme_image', ...)` is a call the first count cannot see) or in a `use function`. A Blade file is not PHP to the
     * tokenizer, so it is read as text.
     *
     * @return array{calls: int, mentions: int}
     */
    private static function usesOfTheHelperIn(string $source, bool $blade): array
    {
        if ($blade) {
            return ['calls' => (int) preg_match_all('/(?<![A-Za-z0-9_>:$])upload_theme_image\s*\(/i', $source), 'mentions' => 0];
        }

        $calls = $mentions = 0;
        $significant = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        foreach ($significant as $i => $token) {
            if (! is_array($token)) {
                continue;
            }
            $before = $significant[$i - 1] ?? null;
            $twoBefore = $significant[$i - 2] ?? null;

            if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE], true)
                && preg_match('/(^|\\\\)upload_theme_image$/i', $token[1]) === 1) {
                if (is_array($before) && $before[0] === T_FUNCTION && ! (is_array($twoBefore) && $twoBefore[0] === T_USE)) {
                    continue;                                   // the definition
                }
                is_array($before) && $before[0] === T_FUNCTION ? $mentions++ : $calls++;      // `use function ...` is a mention
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && strtolower(trim($token[1], "'\"")) === 'upload_theme_image') {
                $guard = is_array($twoBefore) && $twoBefore[0] === T_STRING && strtolower($twoBefore[1]) === 'function_exists' && $before === '(';
                $guard ? null : $mentions++;
            }
        }

        return ['calls' => $calls, 'mentions' => $mentions];
    }

    /**
     * WHO CALLS THE HELPER. The rule lives in the helper because its callers are seven and two of them are vendored; this is the list
     * that claim rests on. A new caller fails here so that somebody decides whether it is a panel upload or a customer's -- for
     * THIS helper's callers the fifth argument is what keeps SVG away from a stranger. Every directory of the repository is
     * walked except the ones named below, and a file is read whatever the case of its `.php`.
     */
    public function test_every_caller_of_the_helper_is_known(): void
    {
        $calls = $mentions = [];
        $skipped = ['vendor', 'node_modules', 'storage', 'tests', 'docs', '.git', '.github', '.claude'];

        foreach (scandir(base_path()) ?: [] as $root) {
            if (in_array($root, ['.', '..'], true) || in_array($root, $skipped, true) || ! is_dir(base_path($root))) {
                continue;
            }

            // A plain directory walk: `File::allFiles()` builds and SORTS an object for every file in the tree, images included.
            $walk = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $entry) => ! ($entry->isDir() && in_array($entry->getFilename(), ['vendor', 'node_modules'], true))
            ));

            foreach ($walk as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (stripos($source, 'upload_theme_image') === false) {
                    continue;        // nothing to tokenize
                }

                $uses = self::usesOfTheHelperIn($source, str_ends_with(strtolower($file->getFilename()), '.blade.php'));
                $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

                if ($uses['calls'] > 0) {
                    $calls[$relative] = $uses['calls'];
                }
                if ($uses['mentions'] > 0) {
                    $mentions[$relative] = $uses['mentions'];
                }
            }
        }

        ksort($calls);

        $this->assertSame([
            'app/Http/Controllers/Api/ApiController.php' => 1,
            'app/Http/Controllers/Api/DashboardController.php' => 3,
            'app/Http/Controllers/PaymentSettingController.php' => 1,
            'packages/workdo/SignInWithFacebook/src/app/Http/Controllers/SignInWithFacebookController.php' => 1,
            'packages/workdo/SignInWithLinkedIn/src/app/Http/Controllers/SignInWithLinkedInController.php' => 1,
        ], $calls, 'The callers of upload_theme_image() changed. Decide for the new one whether it is a customer\'s upload (the fifth argument).');
        $this->assertSame([], $mentions, 'The helper is named as a string or imported somewhere: that can be a call this census does not count.');

        // the counting itself: every form it must see, and the two it must not
        $this->assertSame(['calls' => 4, 'mentions' => 0], self::usesOfTheHelperIn('<?php upload_theme_image($a); \upload_theme_image($a); UPLOAD_THEME_IMAGE($a); $f = upload_theme_image(...); // upload_theme_image($a)' . "\n" . 'if (! function_exists(\'upload_theme_image\')) { function upload_theme_image() {} }', false));
        $this->assertSame(['calls' => 0, 'mentions' => 3], self::usesOfTheHelperIn('<?php use function upload_theme_image; call_user_func(\'upload_theme_image\', $a); $f = "upload_theme_image";', false));

        $avatar = (string) file_get_contents(base_path('app/Http/Controllers/Api/ApiController.php'));
        $this->assertSame(1, substr_count($avatar, 'upload_theme_image($theme_image, 0, $store->created_by, $store->id, true)'),
            'The customer\'s avatar is the one caller that must say it is a customer\'s upload, in the slug\'s store.');

        $payment = (string) file_get_contents(base_path('app/Http/Controllers/PaymentSettingController.php'));
        $this->assertSame(1, substr_count($payment, 'upload_theme_image($image, 0, 0, $this->store_id)'),
            'The web payment settings must name the store whose row they write: the helper\'s own choice follows the account, and the two differ for an account that points at no store.');
    }
}
