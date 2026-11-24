<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SignInImageDeleteGuard;
use App\Models\AddOnManager;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\StoredPathPolicy;
use App\Support\StoreBoundFilesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-70 -- THE VENDORED SIGN-IN SETTINGS HANDLERS DELETE THE FILE NAMED BY THE OLDEST ROW OF A SETTING, WHOSEVER IT IS.
 * ========================================================================================================================
 *
 * SignInWithFacebookController::facebookSettings() and SignInWithLinkedInController::LinkedinSettings() (vendored; not edited) do
 *
 *     $Setting = Setting::where(['name' => '<provider>_sign_in_image'])->first();     // no store predicate; Setting is not a scoped model
 *     if (File::exists(base_path($Setting->value))) File::delete(base_path($Setting->value));
 *
 * after a merchant saves the form with a new image. App\Http\Middleware\SignInImageDeleteGuard recognises the two handlers by their ACTION, runs that same
 * lookup first, and swaps the `File` facade's root for a StoreBoundFilesystem for the lifetime of the request. THE SECOND FORM (this file) is what an
 * independent review of the first one demanded: the first judged only the FILE ("is it in the saver's folder?") and only lexically, so
 *
 *   - a row PLANTED by another tenant (the Store Setting door writes any name for any caller, and needs no plan and no module) naming the VICTIM'S OWN file was
 *     carried out by the victim's own save, silently (BYP-01, T2);
 *   - a `..` that leaves the application and walks back in by its directory name was never judged (BYP-02, COMPAT-01, TST-04);
 *   - anything outside the three upload roots -- `.env`, `config/*`, the vendored ImportExport default image -- was the real filesystem (BYP-03, T1, T3);
 *   - store 0 owned the folder called `0` (BYP-06, TST-02), and the guard was keyed on a route NAME (COMPAT-02, T5).
 *
 * So the filesystem judges the PLACE as the operating system resolves it (realpath of the directory, then the file's own name), allows only the saver's own
 * upload folders and the framework's working areas, and refuses everything else; and the guard hands it whether the ROW belongs to the saver's store, so that a
 * planted row makes every upload delete refuse.
 *
 * THE MODULES ARE LOADED THROUGH THE PRODUCTION LOADER (an `add_on_managers` row): no WorkDo module loads in any test run otherwise, so a test that "passes"
 * against these routes without it never reached them. ORACLE: the file on the REAL filesystem after the request (`File::delete` is not the faked `theme`
 * disk) -- read with file_exists() -- AND the refusal the filesystem logged (a file that survived because the handler never reached the delete would
 * otherwise pass every defect case: TST-05).
 *
 * `uploads/` IS A TRACKED DIRECTORY (see OperatorRootedStoredUploadTest): real folders live under it, so every store here is re-keyed to an id no real folder
 * uses, the ids differ from that class's so the two cannot collide, setUp REFUSES to adopt a folder that already exists, every file this test writes carries
 * `sec70` in its name, and teardown removes only the folders named here.
 */
class SignInImageDeleteGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';

    private const STORE_A = 986021;
    private const STORE_B = 986022;
    private const BOUND = 986023;       // the store a StoreBoundFilesystem is built for in the function-level cases
    private const OTHER = 986024;       // a store that is not BOUND's and not run by BOUND's owner
    private const SIBLING = 986025;     // a store run by the same owner as BOUND
    private const FIXTURE_STORE_IDS = [0, self::STORE_A, self::STORE_B, self::BOUND, self::OTHER, self::SIBLING];     // 0: no store, and the folder called `0`

    private const REFUSED = 'a delete outside the current store was refused';

    /** @var list<string> single files this test created outside the fixture folders (removed afterwards) */
    private array $files = [];

    /** @var list<string> directories this test created that are not a store's folder (removed when empty) */
    private array $directories = [];

    private bool $createdUploadsRoot = false;
    private bool $fixtureFoldersAreOurs = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdUploadsRoot = ! is_dir(base_path('uploads'));
        foreach (['uploads/uploads', 'public/uploads', 'storage/uploads'] as $root) {
            if (! is_dir(base_path($root))) {
                $this->directories[] = base_path($root);       // created by a case below, so removed (when empty) afterwards
            }
        }
        foreach (self::FIXTURE_STORE_IDS as $id) {
            foreach ($this->foldersOf($id) as $folder) {
                $this->assertDirectoryDoesNotExist($folder, 'premise: this fixture folder is nobody\'s');
            }
        }
        // Only now may tearDown delete them: PHPUnit runs tearDown even when an assertion in setUp fails, and a folder this guard has
        // just refused to adopt is by definition not this test's to remove.
        $this->fixtureFoldersAreOurs = true;

        Storage::fake('theme');
    }

    protected function tearDown(): void
    {
        // A FRESH filesystem, not the `File` facade: a mutant (or a defect) that leaves the facade bound would make `deleteDirectory()` -- which
        // removes each file through delete() -- refuse to remove this test's own fixture files, and the next run would refuse to start on the
        // folders left behind, so every later case would look like a failure for the wrong reason (it did, once: the first run of the mutants).
        $files = new \Illuminate\Filesystem\Filesystem();

        foreach ($this->files as $file) {
            if (is_link($file) || file_exists($file)) {
                @unlink($file);
            }
        }

        foreach ($this->fixtureFoldersAreOurs ? self::FIXTURE_STORE_IDS : [] as $id) {
            foreach ($this->foldersOf($id) as $folder) {
                $files->deleteDirectory($folder);
            }
        }

        // the folders the path matrix writes into that are not a store's, and the folder called `0`; `rmdir` removes an EMPTY directory only
        foreach (array_merge(['uploads/sec70-platform', 'uploads/sec70-folder'], $this->directories) as $folder) {
            @rmdir(str_starts_with($folder, '/') ? $folder : base_path($folder));
        }

        if ($this->createdUploadsRoot && is_dir(base_path('uploads')) && count(scandir(base_path('uploads'))) === 2) {
            rmdir(base_path('uploads'));
        }

        parent::tearDown();
    }

    /** @return list<string> every folder a store's id can name under the three upload roots, and the gallery's doubled prefix */
    private function foldersOf(int $id): array
    {
        return [
            base_path('uploads/' . $id),
            base_path('uploads/uploads/' . $id),
            base_path('public/uploads/' . $id),
            base_path('storage/uploads/' . $id),
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // fixtures
    // ---------------------------------------------------------------------------------------------------------------------------

    /** A store on an id no real folder uses (`stores.id` is changed underneath Eloquent, so the model cache is flushed). */
    private function rekey(User $owner, Store $store, int $newId): array
    {
        DB::table('stores')->where('id', $store->id)->update(['id' => $newId]);
        (new Store)->flushCache();

        $owner->current_store = $newId;
        $owner->save();

        return [$owner->fresh(), Store::findOrFail($newId)];
    }

    /**
     * Every module is registered and every owner entitled to it BEFORE the first entitlement is read: `ActivatedModule()` memoises its answer
     * per owner for the life of the process, so a second module enabled after the first read would never be seen.
     *
     * @param array<string, string> $modules module name => package name
     */
    private function enableModules(array $modules, User ...$owners): void
    {
        foreach ($modules as $module => $package) {
            AddOnManager::create(['module' => $module, 'name' => $module, 'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1, 'package_name' => $package]);
            Cache::flush();

            $provider = new \App\Providers\PackageServiceProvider($this->app);
            $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
            $loadAddons->setAccessible(true);
            $loadAddons->invoke($provider, [$module]);

            foreach ($owners as $owner) {
                DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach ($owners as $owner) {
            foreach (array_keys($modules) as $module) {
                $this->assertContains($module, ActivatedModule($owner->id), "Premise: {$owner->id} is entitled to {$module}.");
            }
        }
    }

    /** A real file under the repository's own uploads/<store id>/, where File::delete(base_path(...)) looks. @return string the RELATIVE path a settings row stores */
    private function realFile(int $storeId, string $name): string
    {
        $relative = 'uploads/' . $storeId . '/' . $name;
        File::ensureDirectoryExists(dirname(base_path($relative)));
        file_put_contents(base_path($relative), (string) base64_decode(self::PNG));

        return $relative;
    }

    private function upload(string $name = 'signin.png'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sec70');
        file_put_contents($path, (string) base64_decode(self::PNG));
        $this->files[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function row(string $name, string $value, ?int $storeId, int $createdBy): Setting
    {
        // Eloquent, not DB::table(): Setting is Cachable and a raw write leaves the handler's read serving the row it had
        return Setting::create(['name' => $name, 'value' => $value, 'store_id' => $storeId, 'created_by' => $createdBy]);
    }

    private function saveFacebook(User $merchant, array $extra = [], array $headers = [], string $uri = '/facebooklogin-setting')
    {
        return $this->actingAs($merchant)->withHeaders($headers)->post($uri, array_merge([
            'facebook_setting_enabled' => 'on', 'facebook_client_id' => 'client', 'facebook_client_secret' => 'secret',
            'facebook_callback' => 'https://example.test/auth/facebook/callback', 'facebook_sign_in_image' => $this->upload(),
        ], $extra));
    }

    private function saveLinkedIn(User $merchant)
    {
        return $this->actingAs($merchant)->post('/linkedin-setting', [
            'linkedin_setting_enabled' => 'on', 'linkedin_client_id' => 'client', 'linkedin_client_secret' => 'secret',
            'linkedin_callback' => 'https://example.test/auth/linkedin/callback', 'linkedin_sign_in_image' => $this->upload(),
        ]);
    }

    /** @return array{0: User, 1: Store, 2: User, 3: Store} two tenants entitled to both modules, on ids no real folder uses */
    private function twoTenants(): array
    {
        [$a, $storeA] = $this->makeTenant();
        [$a, $storeA] = $this->rekey($a, $storeA, self::STORE_A);
        [$b, $storeB] = $this->makeTenant();
        [$b, $storeB] = $this->rekey($b, $storeB, self::STORE_B);

        $this->enableModules(['SignInWithFacebook' => 'sign-in-with-facebook', 'SignInWithLinkedIn' => 'sign-in-with-linkedin'], $a, $b);

        // routes the second module added after boot are named by a chained ->name(): the lookup table is rebuilt, as the framework does after loading
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertTrue(\Illuminate\Support\Facades\Route::has('facebooklogin.settings'), 'Premise: the Facebook module routes are registered.');
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('linkedinlogin.settings'), 'Premise: the LinkedIn module routes are registered.');

        return [$a, $storeA, $b, $storeB];
    }

    /**
     * Every refusal the store-bound filesystem logs while $do runs: [context, ...]. A file that SURVIVED because the handler never reached the delete would
     * pass every defect case on the file alone; the refusal is what proves the handler did reach it and was stopped (TST-05).
     *
     * @return list<array<string, mixed>>
     */
    private function refusalsDuring(callable $do): array
    {
        $logged = [];
        $listener = function (MessageLogged $event) use (&$logged) {
            if ($event->message === self::REFUSED) {
                $logged[] = $event->context + ['level' => $event->level];
            }
        };
        Event::listen(MessageLogged::class, $listener);

        $do();

        return $logged;
    }

    private function assertTheFacadeIsTheRealOne(string $when): void
    {
        $this->assertNotInstanceOf(StoreBoundFilesystem::class, File::getFacadeRoot(), "The File facade was still store-bound {$when}.");
        $this->assertNotInstanceOf(StoreBoundFilesystem::class, app('files'), "The container's `files` was still store-bound {$when}.");
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the defect, through the real routes
    // ---------------------------------------------------------------------------------------------------------------------------

    /** THE FIRST TENANT'S IMAGE IS DELETED BY THE SECOND TENANT'S SAVE: the oldest row of that name is the first tenant's. */
    public function test_a_second_tenants_facebook_save_does_not_delete_the_first_tenants_image(): void
    {
        [$a, $storeA, $b] = $this->twoTenants();
        $mine = $this->realFile((int) $storeA->id, 'sec70-a-facebook.png');
        $this->row('facebook_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);
        $this->assertSame((int) $storeA->id, (int) Setting::where(['name' => 'facebook_sign_in_image'])->first()->store_id, 'Premise: the handler will find the FIRST tenant\'s row.');

        $refusals = $this->refusalsDuring(fn () => $this->saveFacebook($b));

        $this->assertFileExists(base_path($mine), 'Tenant B\'s save of the Facebook sign-in settings deleted the file tenant A\'s row names.');
        $this->assertSame(1, Setting::where('name', 'facebook_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the request reached the handler and saved tenant B\'s own row.');
        $this->assertSame([['store' => self::STORE_B, 'reason' => 'row', 'level' => 'warning']], $refusals, 'Premise: the handler reached the delete, and the row it chose was refused as not this store\'s.');
        $this->assertTheFacadeIsTheRealOne('after a real request');
    }

    public function test_a_second_tenants_linkedin_save_does_not_delete_the_first_tenants_image(): void
    {
        [$a, $storeA, $b] = $this->twoTenants();
        $mine = $this->realFile((int) $storeA->id, 'sec70-a-linkedin.png');
        $this->row('linkedin_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);

        $refusals = $this->refusalsDuring(fn () => $this->saveLinkedIn($b));

        $this->assertFileExists(base_path($mine), 'Tenant B\'s save of the LinkedIn sign-in settings deleted the file tenant A\'s row names.');
        $this->assertSame(1, Setting::where('name', 'linkedin_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the request reached the handler and saved tenant B\'s own row.');
        $this->assertCount(1, $refusals, 'Premise: the handler reached the delete and it was refused.');
    }

    /** A ROW WRITTEN TO POINT ELSEWHERE, by the saver: the Store Setting handlers take every posted name into `settings` for the caller's store. */
    public function test_a_row_that_names_another_tenants_file_does_not_get_it_deleted(): void
    {
        [$a, $storeA, $b, $storeB] = $this->twoTenants();
        $victim = $this->realFile((int) $storeA->id, 'sec70-victim.png');
        $this->row('facebook_sign_in_image', $victim, (int) $storeB->id, (int) $b->id);      // B's own row, naming A's file; no other row exists, so it is the first

        $refusals = $this->refusalsDuring(fn () => $this->saveFacebook($b));

        $this->assertFileExists(base_path($victim), 'A merchant who wrote their own sign-in image row got another tenant\'s file deleted.');
        $this->assertSame([['store' => self::STORE_B, 'reason' => 'path', 'level' => 'warning']], $refusals, 'Premise: the row is B\'s own, so what refused the delete is the PLACE, not the row.');
    }

    /**
     * THE SECOND WAY IN, REVERSED (BYP-01, T2): a row planted by ANOTHER tenant that names the VICTIM'S OWN file. The planter's own save is refused (the case above);
     * the victim's routine save used to carry it out, silently, because the file really is in the victim's folder. The planter needs no plan and no module: the
     * Store Setting door writes any name for any caller.
     */
    public function test_a_row_another_tenant_planted_naming_the_savers_own_file_does_not_get_it_deleted(): void
    {
        [$a, $storeA, $b, $storeB] = $this->twoTenants();
        $victimsOwn = $this->realFile((int) $storeB->id, 'sec70-victims-own.png');
        $planted = $this->row('facebook_sign_in_image', $victimsOwn, (int) $storeA->id, (int) $a->id);       // A is the planter: the oldest row of the name, in A's store
        $this->assertSame((int) $planted->id, (int) Setting::where(['name' => 'facebook_sign_in_image'])->first()->id, 'Premise: the planted row is the one the handler finds.');

        $refusals = $this->refusalsDuring(fn () => $this->saveFacebook($b));

        $this->assertFileExists(base_path($victimsOwn), 'The victim\'s OWN save deleted their OWN file, because another tenant planted the row the handler chose.');
        $this->assertSame(1, Setting::where('name', 'facebook_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the victim\'s save reached the end of the handler.');
        $this->assertSame([['store' => self::STORE_B, 'reason' => 'row', 'level' => 'warning']], $refusals, 'Premise: the delete was reached and refused BECAUSE the row is not the saver\'s.');
    }

    public function test_the_same_planted_row_does_not_work_through_linkedin_either(): void
    {
        [$a, $storeA, $b, $storeB] = $this->twoTenants();
        $victimsOwn = $this->realFile((int) $storeB->id, 'sec70-victims-own-linkedin.png');
        $this->row('linkedin_sign_in_image', $victimsOwn, (int) $storeA->id, (int) $a->id);

        $refusals = $this->refusalsDuring(fn () => $this->saveLinkedIn($b));

        $this->assertFileExists(base_path($victimsOwn));
        $this->assertCount(1, $refusals);
    }

    /** A row with NO store (legacy data) is not provably the saver's, so it does not get a delete either: a leak of disk, never of someone's file. */
    public function test_a_row_with_no_store_does_not_delete_the_savers_own_file(): void
    {
        [, , $b, $storeB] = $this->twoTenants();
        $own = $this->realFile((int) $storeB->id, 'sec70-no-store-row.png');
        $this->row('facebook_sign_in_image', $own, null, (int) $b->id);

        $refusals = $this->refusalsDuring(fn () => $this->saveFacebook($b));

        $this->assertFileExists(base_path($own));
        $this->assertSame('row', $refusals[0]['reason'] ?? null);
    }

    /** THE CONTROL THE FIX MUST NOT BREAK: a tenant that is alone still replaces its own previous image. */
    public function test_a_tenants_own_previous_image_is_still_deleted_when_it_saves_a_new_one(): void
    {
        [, , $b, $storeB] = $this->twoTenants();
        $old = $this->realFile((int) $storeB->id, 'sec70-own-old.png');
        $this->row('facebook_sign_in_image', $old, (int) $storeB->id, (int) $b->id);

        $refusals = $this->refusalsDuring(fn () => $this->saveFacebook($b));

        $this->assertFileDoesNotExist(base_path($old), 'Premise: the handler replaces the signed-in tenant\'s own previous image (the control). The guard refused a delete in the tenant\'s own folder.');
        $this->assertSame([], $refusals, 'The control must not be refused.');
    }

    /** The same control for LinkedIn, and a request to some OTHER route is not bound (see the middleware cases). */
    public function test_a_tenants_own_previous_linkedin_image_is_still_deleted_when_it_saves_a_new_one(): void
    {
        [, , $b, $storeB] = $this->twoTenants();
        $old = $this->realFile((int) $storeB->id, 'sec70-own-old-linkedin.png');
        $this->row('linkedin_sign_in_image', $old, (int) $storeB->id, (int) $b->id);

        $this->saveLinkedIn($b);

        $this->assertFileDoesNotExist(base_path($old), 'Premise: the handler replaces the signed-in tenant\'s own previous LinkedIn image (the control).');
    }

    /** A store its owner also runs is the same tenant (the rule StoredPathPolicy::isSafe() already applies): the row of one is the other's, and so is its file. */
    public function test_a_row_in_another_store_of_the_same_owner_is_still_the_savers_to_replace(): void
    {
        [$owner, $first] = $this->makeTenant();
        [$owner, $first] = $this->rekey($owner, $first, self::STORE_A);
        $second = $this->makeStore($owner);
        DB::table('stores')->where('id', $second->id)->update(['id' => self::STORE_B]);
        (new Store)->flushCache();
        $owner->current_store = self::STORE_B;
        $owner->save();
        $owner = $owner->fresh();

        $this->enableModules(['SignInWithFacebook' => 'sign-in-with-facebook', 'SignInWithLinkedIn' => 'sign-in-with-linkedin'], $owner);
        app('router')->getRoutes()->refreshNameLookups();

        $old = $this->realFile(self::STORE_A, 'sec70-sibling-old.png');
        $this->row('facebook_sign_in_image', $old, self::STORE_A, (int) $owner->id);       // the row of the owner's FIRST store, the file in its folder; the owner now saves from the SECOND

        $this->saveFacebook($owner);

        $this->assertFileDoesNotExist(base_path($old), 'A store the same owner runs was treated as another tenant.');
    }

    /**
     * WHO DECIDES THE STORE (TST-03). A predicate built from caller-supplied input is not a tenant check: a posted store_id, a current_store field and an
     * X-Store-Id header naming the victim's store must change nothing, and neither must the platform operator being the one who saves.
     */
    public function test_a_posted_store_a_header_or_the_operator_cannot_unbind_the_filesystem(): void
    {
        [$a, $storeA, $b] = $this->twoTenants();
        $mine = $this->realFile((int) $storeA->id, 'sec70-a-hostile.png');
        $this->row('facebook_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);

        $this->saveFacebook($b, ['store_id' => self::STORE_A, 'current_store' => self::STORE_A], ['X-Store-Id' => (string) self::STORE_A]);

        $this->assertFileExists(base_path($mine), 'A posted store id, current_store or X-Store-Id header named the victim\'s store and the delete was carried out.');
        $this->assertSame(1, Setting::where('name', 'facebook_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the request reached the end of the handler as tenant B.');
    }

    public function test_the_platform_operator_is_bound_to_their_current_store_like_anybody_else(): void
    {
        [$a, $storeA] = $this->twoTenants();
        $operator = $this->makePlatformAdmin();
        $operatorStore = $this->makeStore($operator);
        [$operator] = $this->rekey($operator, $operatorStore, self::OTHER);
        $mine = $this->realFile((int) $storeA->id, 'sec70-a-operator.png');
        $this->row('facebook_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);

        $refusals = $this->refusalsDuring(fn () => $this->saveFacebook($operator));

        $this->assertFileExists(base_path($mine), 'The platform operator, saving from their own store, deleted a tenant\'s file.');
        $this->assertSame(1, Setting::where('name', 'facebook_sign_in_image')->where('store_id', self::OTHER)->count(), 'Premise: the operator\'s request reached the end of the handler.');
        $this->assertCount(1, $refusals);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the middleware
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_guard_is_on_the_web_group_so_the_modules_routes_pass_through_it(): void
    {
        $this->assertContains(SignInImageDeleteGuard::class, app('router')->getMiddlewareGroups()['web'] ?? []);
    }

    /** The guard is keyed on the ACTION, and every route that reaches one of the two handlers carries it (COMPAT-02, T5). */
    public function test_the_guard_names_the_two_handlers_by_action_and_every_route_to_them_carries_it(): void
    {
        $this->twoTenants();

        $this->assertSame(
            ['SignInWithFacebookController@facebookSettings' => 'facebook_sign_in_image', 'SignInWithLinkedInController@LinkedinSettings' => 'linkedin_sign_in_image'],
            SignInImageDeleteGuard::HANDLERS
        );

        foreach (SignInImageDeleteGuard::HANDLERS as $suffix => $row) {
            $routes = array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_ends_with((string) $route->getActionName(), $suffix));

            $this->assertNotEmpty($routes, "Premise: a route reaches {$suffix}.");

            foreach ($routes as $route) {
                $this->assertContains(SignInImageDeleteGuard::class, app('router')->gatherRouteMiddleware($route), "A route to {$suffix} does not carry the guard.");
            }
        }
    }

    /** A SECOND route to the same handler -- an alias, a re-declaration -- is bound all the same: the guard follows the handler, not a name. */
    public function test_an_alias_route_to_the_same_handler_is_bound_too(): void
    {
        [$a, $storeA, $b] = $this->twoTenants();
        $routes = array_values(array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_ends_with((string) $route->getActionName(), 'SignInWithFacebookController@facebookSettings')));
        $this->assertNotEmpty($routes);
        $action = $routes[0]->getActionName();

        \Illuminate\Support\Facades\Route::middleware(['auth', 'web'])->post('/sec70-alias-facebook', $action)->name('sec70.alias.facebook');
        app('router')->getRoutes()->refreshNameLookups();

        $mine = $this->realFile((int) $storeA->id, 'sec70-a-alias.png');
        $this->row('facebook_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);

        $this->saveFacebook($b, [], [], '/sec70-alias-facebook');

        $this->assertFileExists(base_path($mine), 'A second route to the vendored handler was not bound: the guard followed a name instead of the handler.');
        $this->assertSame(1, Setting::where('name', 'facebook_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the alias reached the handler.');
    }

    private function requestToAction(string $action, string $method = 'POST'): Request
    {
        $request = Request::create('/x', $method);
        $route = new Route([$method], '/x', ['uses' => $action, 'controller' => $action]);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function facebookRequest(string $method = 'POST'): Request
    {
        return $this->requestToAction('Workdo\\SignInWithFacebook\\app\\Http\\Controllers\\SignInWithFacebookController@facebookSettings', $method);
    }

    public function test_the_file_facade_is_put_back_after_a_request_to_a_guarded_route_whatever_the_handler_does(): void
    {
        [$a] = $this->makeTenant();
        $this->actingAs($a);
        $original = File::getFacadeRoot();
        $guard = new SignInImageDeleteGuard();

        $inside = null;
        $guard->handle($this->facebookRequest(), function () use (&$inside) {
            $inside = File::getFacadeRoot();

            return response('ok');
        });

        $this->assertInstanceOf(StoreBoundFilesystem::class, $inside, 'Premise: inside a guarded route the File facade is store-bound.');
        $this->assertSame($original, File::getFacadeRoot(), 'The File facade was not put back after a normal request.');

        // the response the vendored handlers actually return is a redirect: restoring conditionally on the response type would pass the case above
        $guard->handle($this->facebookRequest(), fn () => redirect('/x'));
        $this->assertSame($original, File::getFacadeRoot(), 'The File facade was not put back after a redirect, which is what both handlers return.');

        try {
            $guard->handle($this->requestToAction('Workdo\\SignInWithLinkedIn\\app\\Http\\Controllers\\SignInWithLinkedInController@LinkedinSettings'), fn () => throw new \RuntimeException('the handler failed'));
            $this->fail('Premise: the handler\'s exception propagates.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($original, File::getFacadeRoot(), 'The File facade was not put back after a handler that threw.');
        $this->assertSame($original, app('files'), 'The container\'s `files` binding was not put back.');
    }

    public function test_any_other_route_or_method_is_not_touched(): void
    {
        [$a] = $this->makeTenant();
        $this->actingAs($a);
        $original = File::getFacadeRoot();
        $guard = new SignInImageDeleteGuard();

        $requests = [
            'a POST to some other action' => $this->requestToAction('App\\Http\\Controllers\\HomeController@index'),
            'a GET to a guarded action' => $this->facebookRequest('GET'),
            'a request with no route' => Request::create('/x', 'POST'),
            'a lookalike controller name' => $this->requestToAction('App\\Http\\Controllers\\MySignInWithFacebookControllerX@facebookSettings'),
        ];

        foreach ($requests as $what => $request) {
            $seen = null;
            $guard->handle($request, function () use (&$seen) {
                $seen = File::getFacadeRoot();

                return response('ok');
            });

            $this->assertSame($original, $seen, "{$what} got a store-bound File facade.");
        }
    }

    /** An unknown store owns nothing: store 0, which owns no folder either -- not even the one called `0` -- and is not `null` ("no store to check"). */
    public function test_a_request_with_no_signed_in_user_is_bound_to_a_store_that_owns_nothing(): void
    {
        $victim = $this->realFile(self::OTHER, 'sec70-nobody.png');
        $zero = $this->realFile(0, 'sec70-zero.png');
        $guard = new SignInImageDeleteGuard();
        $answers = [];

        $refusals = $this->refusalsDuring(function () use ($guard, $victim, $zero, &$answers) {
            $guard->handle($this->facebookRequest(), function () use (&$answers, $victim, $zero) {
                $answers = [File::delete(base_path($victim)), File::delete(base_path($zero))];

                return response('ok');
            });
        });

        $this->assertSame([false, false], $answers, 'Premise: both deletes were refused.');
        $this->assertFileExists(base_path($victim), 'A request with nobody signed in deleted an upload.');
        $this->assertFileExists(base_path($zero), 'Store 0 owned the folder called 0.');
        $this->assertSame(['no store', 'no store'], array_column($refusals, 'reason'));
    }

    /** A signed-in user whose store cannot be resolved is the same: bound to nothing, not to store 1 and not to "no store to check". */
    public function test_a_signed_in_user_with_no_store_is_bound_to_a_store_that_owns_nothing(): void
    {
        $user = User::factory()->create(['type' => 'admin', 'created_by' => 1, 'current_store' => null, 'is_active' => 1, 'email_verified_at' => now()]);
        $this->actingAs($user);
        $this->assertSame(0, (int) getCurrentStore(), 'Premise: no store resolves for this user.');
        $first = $this->realFile(self::STORE_A, 'sec70-first-store.png');

        $answer = null;
        (new SignInImageDeleteGuard())->handle($this->facebookRequest(), function () use (&$answer, $first) {
            $answer = File::delete(base_path($first));

            return response('ok');
        });

        $this->assertFalse($answer);
        $this->assertFileExists(base_path($first), 'A user with no store was bound to another store\'s folder (or to "no store to check").');
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the filesystem: the PLACE, as the operating system resolves it
    // ---------------------------------------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string|null}> a path relative to the repository, and why a BOUND-store filesystem refuses it (null = it may delete it).
     *         The reason is asserted: a refusal asserted without its reason is also what a crash or an unresolvable path produces.
     */
    public static function thePaths(): array
    {
        $own = self::BOUND;
        $other = self::OTHER;
        $app = '{app}';

        return [
            'its own folder' => ["uploads/{$own}/sec70-a.png", null],
            'its own folder, the gallery\'s doubled uploads/' => ["uploads/uploads/{$own}/sec70-a.png", null],
            'another store\'s folder' => ["uploads/{$other}/sec70-a.png", 'path'],
            'another store\'s folder, the gallery\'s doubled uploads/' => ["uploads/uploads/{$other}/sec70-a.png", 'path'],
            'a platform folder that no store owns' => ['uploads/sec70-platform/sec70-a.png', 'path'],
            'a folder of its own, no store id' => ['uploads/sec70-folder/sec70-a.png', 'path'],
            'public/uploads, another store' => ["public/uploads/{$other}/sec70-a.png", 'path'],
            'public/uploads, its own' => ["public/uploads/{$own}/sec70-a.png", null],
            'storage/uploads, another store' => ["storage/uploads/{$other}/sec70-a.png", 'path'],
            'storage/uploads, its own' => ["storage/uploads/{$own}/sec70-a.png", null],
            'the framework\'s own files' => ['storage/framework/testing/sec70-framework.txt', null],
            // WALKS (TST-01: five wrong resolvers survived a matrix with one-segment prefixes only)
            'a walk from its own folder into another\'s' => ["uploads/{$own}/../{$other}/sec70-a.png", 'path'],
            'a walk from its own folder into the framework\'s area' => ["uploads/{$own}/../../storage/framework/testing/sec70-outside.txt", null],
            'a walk into uploads from outside it' => ["storage/../uploads/{$other}/sec70-a.png", 'path'],
            'a walk into ITS OWN folder from outside' => ["storage/../uploads/{$own}/sec70-a.png", null],
            'two walks in one path' => ["storage/../uploads/{$own}/../{$other}/sec70-a.png", 'path'],
            'a prefix of two segments, two walks' => ["storage/framework/../../uploads/{$other}/sec70-a.png", 'path'],
            'a `.` segment' => ["config/./../uploads/{$other}/sec70-a.png", 'path'],
            'an empty segment' => ["config//../uploads/{$other}/sec70-a.png", 'path'],
            'a walk into public/uploads' => ["config/../public/uploads/{$other}/sec70-a.png", 'path'],
            // OUT AND BACK IN (BYP-02, COMPAT-01, TST-04): leave the application with `..` and re-enter it by its own directory name
            'out and back in, another store\'s folder' => ["../{$app}/uploads/{$other}/sec70-a.png", 'path'],
            'out and back in, from storage' => ["storage/../../{$app}/uploads/{$other}/sec70-a.png", 'path'],
            'out and back in, its own folder' => ["../{$app}/uploads/{$own}/sec70-a.png", null],
            // a SIBLING folder whose name merely begins with an upload root is not under it (a prefix test without the separator reads `uploads986023/x` as the root plus `986023/x`)
            'a sibling folder whose name begins with uploads' => ["uploads{$own}/sec70-a.png", 'outside'],
            'the framework\'s other working area' => ['bootstrap/cache/sec70-cache.txt', null],
            // CANONICAL FOLDER (BYP-06): `0986023` is not store 986023's folder, though (int) of it is
            'a folder spelled with a leading zero' => ["uploads/0{$own}/sec70-a.png", 'path'],
            // DEFAULT DENY (BYP-03, T1, T3): nothing outside the saver's folders and the framework's areas
            'a file at the root' => ['sec70-root.txt', 'outside'],
            'a file in storage/app' => ['storage/app/sec70-app.txt', 'outside'],
            'a file in config' => ['config/sec70-config.txt', 'outside'],
        ];
    }

    /** Make every directory a path WALKS THROUGH exist, so that "refused" can never mean "the operating system could not resolve it" (the lesson of SD11). */
    private function ensureWalk(string $absolute): void
    {
        $segments = explode('/', $absolute);
        $stack = [];

        foreach ($segments as $i => $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($stack);

                continue;
            }

            $stack[] = $segment;

            if ($i < count($segments) - 1) {
                $directory = '/' . implode('/', $stack);

                if (! is_dir($directory)) {
                    File::ensureDirectoryExists($directory);
                    $this->directories[] = $directory;
                }
            }
        }
    }

    /** The path as the operating system resolves it, without needing it to exist (so `a/../b` is `b`, and a leading `..` leaves the application). */
    private function resolve(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $segment === '..' ? array_pop($out) : $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    #[DataProvider('thePaths')]
    public function test_a_store_bound_filesystem_deletes_only_what_the_store_may(string $relative, ?string $refusedBecause): void
    {
        $relative = str_replace('{app}', basename(base_path()), $relative);
        $absolute = base_path($relative);
        $resolved = $this->resolve($absolute);
        $this->assertStringContainsString('sec70', $resolved, 'Premise: only a file this test names is ever written.');
        $this->ensureWalk($absolute);
        File::ensureDirectoryExists(dirname($resolved));

        file_put_contents($resolved, 'x');
        $this->files[] = $resolved;
        $this->assertFileExists($absolute, 'Premise: the path as written resolves to the file this case wrote, so a refusal cannot mean "could not be resolved".');

        $fs = new StoreBoundFilesystem(self::BOUND);
        $refusals = $this->refusalsDuring(function () use ($fs, $absolute, &$answer) {
            $answer = $fs->delete($absolute);
        });

        if ($refusedBecause === null) {
            $this->assertTrue($answer, "A delete of {$relative} was refused.");
            $this->assertFileDoesNotExist($resolved, "{$relative} was allowed and not deleted.");
            $this->assertSame([], $refusals);
        } else {
            $this->assertFalse($answer, "A delete of {$relative} was not reported as refused.");
            $this->assertFileExists($resolved, "{$relative} was deleted by a store-bound filesystem.");
            $this->assertSame([['store' => self::BOUND, 'reason' => $refusedBecause, 'level' => 'warning']], $refusals, "{$relative} was refused for the wrong reason.");
        }
    }

    /** A directory symlink inside the saver's own folder that leads into another store's is another store's folder (BYP-07). */
    public function test_a_symlinked_directory_is_judged_where_it_leads(): void
    {
        $victim = $this->realFile(self::OTHER, 'sec70-symlink-victim.png');
        $this->realFile(self::BOUND, 'sec70-own.png');
        $link = base_path('uploads/' . self::BOUND . '/sec70-lnk');
        $this->assertTrue(symlink('../' . self::OTHER, $link), 'Premise: a symlink can be made here.');
        $this->files[] = $link;

        $answer = (new StoreBoundFilesystem(self::BOUND))->delete($link . '/sec70-symlink-victim.png');

        $this->assertFalse($answer, 'A symlink inside the saver\'s own folder carried a delete into another store\'s folder.');
        $this->assertFileExists(base_path($victim));
    }

    /** A FINAL symlink is the link's own place: unlink removes the link and never follows it, so a link in the saver's folder is theirs to remove and its target survives. */
    public function test_a_symlink_in_the_savers_own_folder_is_removed_without_touching_its_target(): void
    {
        $victim = $this->realFile(self::OTHER, 'sec70-link-target.png');
        $this->realFile(self::BOUND, 'sec70-own.png');
        $link = base_path('uploads/' . self::BOUND . '/sec70-link.png');
        $this->assertTrue(symlink('../' . self::OTHER . '/sec70-link-target.png', $link), 'Premise: a symlink can be made here.');
        $this->files[] = $link;

        $answer = (new StoreBoundFilesystem(self::BOUND))->delete($link);

        $this->assertTrue($answer);
        $this->assertFalse(is_link($link), 'The link was not removed.');
        $this->assertFileExists(base_path($victim), 'Removing a link in the saver\'s own folder removed the file it points at.');
    }

    /** A link whose target is gone is still a link, and unlink removes it: `is_file()` follows the link and says there is nothing there. */
    public function test_a_dangling_symlink_in_the_savers_own_folder_is_removed(): void
    {
        $this->realFile(self::BOUND, 'sec70-own.png');
        $link = base_path('uploads/' . self::BOUND . '/sec70-dangling.png');
        $this->assertTrue(symlink('/nonexistent/sec70-no-such-target.png', $link), 'Premise: a symlink can be made here.');
        $this->assertFalse(file_exists($link), 'Premise: the link is dangling.');
        $this->files[] = $link;

        $this->assertTrue((new StoreBoundFilesystem(self::BOUND))->delete($link), 'A dangling link in the saver\'s own folder was not removed.');
        $this->assertFalse(is_link($link), 'The dangling link is still there.');
    }

    /** A store that is not a store owns nothing: not another store's folder, not the folder called `0` (BYP-06, TST-02). */
    public function test_store_zero_owns_nothing_and_not_even_the_folder_called_zero(): void
    {
        $zero = $this->realFile(0, 'sec70-zero.png');
        $other = $this->realFile(self::OTHER, 'sec70-zero-other.png');
        $fs = new StoreBoundFilesystem(0);

        $refusals = $this->refusalsDuring(function () use ($fs, $zero, $other, &$answers) {
            $answers = [$fs->delete(base_path($zero)), $fs->delete(base_path($other))];
        });

        $this->assertSame([false, false], $answers);
        $this->assertFileExists(base_path($zero));
        $this->assertFileExists(base_path($other));
        $this->assertSame(['no store', 'no store'], array_column($refusals, 'reason'));
        $this->assertSame([0, 0], array_column($refusals, 'store'));
    }

    /** When the row is not the saver's, EVERY upload delete refuses -- even the saver's own file -- and the framework's own working files still go. */
    public function test_when_the_row_is_not_the_stores_every_upload_delete_is_refused_and_framework_files_still_go(): void
    {
        $own = $this->realFile(self::BOUND, 'sec70-own-but-planted.png');
        $framework = base_path('storage/framework/testing/sec70-framework-2.txt');
        File::ensureDirectoryExists(dirname($framework));
        file_put_contents($framework, 'x');
        $this->files[] = $framework;
        $fs = new StoreBoundFilesystem(self::BOUND, false);

        $refusals = $this->refusalsDuring(function () use ($fs, $own, $framework, &$answers) {
            $answers = [$fs->delete(base_path($own)), $fs->delete($framework)];
        });

        $this->assertSame([false, true], $answers);
        $this->assertFileExists(base_path($own));
        $this->assertFileDoesNotExist($framework);
        $this->assertSame([['store' => self::BOUND, 'reason' => 'row', 'level' => 'warning']], $refusals);
    }

    /** What the real filesystem answers for "nothing there": false, and quietly -- a missing file, a directory, an empty value, a trailing slash, a NUL. */
    public function test_a_path_with_nothing_to_remove_answers_false_and_is_not_logged(): void
    {
        $this->realFile(self::BOUND, 'sec70-own.png');
        $fs = new StoreBoundFilesystem(self::BOUND);

        $refusals = $this->refusalsDuring(function () use ($fs, &$answers) {
            $answers = [
                'a missing file' => $fs->delete(base_path('uploads/' . self::BOUND . '/sec70-missing.png')),
                'a directory' => $fs->delete(base_path('uploads/' . self::BOUND)),
                'a directory with a slash' => $fs->delete(base_path('uploads/' . self::BOUND) . '/'),
                'an empty value' => $fs->delete(''),
                'the application root' => $fs->delete(base_path()),
                'a NUL' => $fs->delete(base_path('uploads/' . self::BOUND . "/sec70-own.png\0x")),
                'a directory that is not there' => $fs->delete(base_path('uploads/sec70-nowhere/sec70-x.png')),
            ];
        });

        $this->assertSame(array_fill_keys(array_keys($answers), false), $answers);
        $this->assertSame([], $refusals, 'A path with nothing to remove was logged as a refusal.');
        $this->assertFileExists(base_path('uploads/' . self::BOUND . '/sec70-own.png'));
    }

    /** A store its owner also runs is the same tenant: the rule StoredPathPolicy::isSafe() already applies to the write side. */
    public function test_a_store_its_owner_also_runs_is_the_same_tenant(): void
    {
        [$owner, $store] = $this->makeTenant();
        [$owner, $store] = $this->rekey($owner, $store, self::BOUND);
        $sibling = $this->makeStore($owner);
        DB::table('stores')->where('id', $sibling->id)->update(['id' => self::SIBLING]);
        (new Store)->flushCache();

        [$stranger, $strangerStore] = $this->makeTenant();
        [, $strangerStore] = $this->rekey($stranger, $strangerStore, self::OTHER);

        $own = $this->realFile(self::SIBLING, 'sec70-sibling.png');
        $theirs = $this->realFile(self::OTHER, 'sec70-stranger.png');

        $fs = new StoreBoundFilesystem(self::BOUND);

        $this->assertTrue($fs->delete(base_path($own)), 'A store the same owner runs was treated as another tenant.');
        $this->assertFileDoesNotExist(base_path($own));
        $this->assertFalse($fs->delete(base_path($theirs)), 'Another owner\'s store was treated as the same tenant.');
        $this->assertFileExists(base_path($theirs));
    }

    /** Several paths at once, as an array and as arguments: the allowed ones go, the refused ones stay, and the answer is false. */
    public function test_a_mixed_batch_deletes_what_it_may_and_says_it_refused_something(): void
    {
        $mine = $this->realFile(self::BOUND, 'sec70-mine.png');
        $theirs = $this->realFile(self::OTHER, 'sec70-theirs.png');
        $fs = new StoreBoundFilesystem(self::BOUND);

        $refusals = $this->refusalsDuring(function () use ($fs, $mine, $theirs, &$answer) {
            $answer = $fs->delete([base_path($mine), base_path($theirs), base_path($theirs)]);
        });

        $this->assertFalse($answer, 'A batch with a refused path answered true.');
        $this->assertFileDoesNotExist(base_path($mine), 'The allowed path of a mixed batch was not deleted.');
        $this->assertFileExists(base_path($theirs), 'The refused path of a mixed batch was deleted.');
        $this->assertCount(2, $refusals, 'One warning per refused PATH: two refused paths were logged as ' . count($refusals) . '.');

        $mine = $this->realFile(self::BOUND, 'sec70-mine-2.png');
        $this->assertFalse($fs->delete(base_path($mine), base_path($theirs)), 'The variadic form with a refused path answered true.');
        $this->assertFileDoesNotExist(base_path($mine));
        $this->assertFileExists(base_path($theirs));
    }

    /** A delete that is ALLOWED but cannot be carried out answers false, as the real filesystem does, and an empty batch answers true. */
    public function test_the_answer_is_the_real_filesystems_when_an_allowed_delete_has_nothing_to_remove(): void
    {
        $fs = new StoreBoundFilesystem(self::BOUND);
        $mine = $this->realFile(self::BOUND, 'sec70-mine-3.png');

        $this->assertFalse($fs->delete([base_path($mine), base_path('uploads/' . self::BOUND . '/sec70-missing-2.png')]), 'A failed allowed delete answered true.');
        $this->assertFileDoesNotExist(base_path($mine));
        $this->assertTrue($fs->delete([]), 'An empty batch answers true, as Filesystem::delete() does.');
    }

    /** The directory calls would answer `true` while this class refused every file in them (COMPAT-05): only the framework's own working areas may be emptied. */
    public function test_directory_calls_are_bound_too(): void
    {
        $theirs = $this->realFile(self::OTHER, 'sec70-dir-victim.png');
        $own = $this->realFile(self::BOUND, 'sec70-dir-own.png');
        $fs = new StoreBoundFilesystem(self::BOUND);

        $refusals = $this->refusalsDuring(function () use ($fs, &$answers) {
            $answers = [
                $fs->deleteDirectory(base_path('uploads/' . self::OTHER)),
                $fs->cleanDirectory(base_path('uploads/' . self::OTHER)),
                $fs->deleteDirectory(base_path('uploads/' . self::BOUND)),
            ];
        });

        $this->assertSame([false, false, false], $answers, 'A directory call over an upload folder answered true.');
        $this->assertFileExists(base_path($theirs));
        $this->assertFileExists(base_path($own), 'Not even the saver\'s own folder may be removed: the handlers delete a file, never a folder.');
        $this->assertSame(['directory', 'directory', 'directory'], array_column($refusals, 'reason'));

        $directory = base_path('storage/framework/testing/sec70-dir');
        File::ensureDirectoryExists($directory);
        file_put_contents($directory . '/sec70-inside.txt', 'x');
        $this->directories[] = $directory;
        $this->assertTrue($fs->deleteDirectory($directory), 'The framework\'s own working area was refused.');
        $this->assertDirectoryDoesNotExist($directory);
    }

    /** It exists to stop deletes: a read is the real filesystem, whoever's the folder. (A write is not bound either, and nothing on the two routes calls one.) */
    public function test_everything_but_delete_is_the_real_filesystem(): void
    {
        $fs = new StoreBoundFilesystem(self::BOUND);
        $target = base_path('storage/framework/testing/sec70-passthrough.txt');
        $this->files[] = $target;
        File::ensureDirectoryExists(dirname($target));

        $fs->put($target, 'hello');
        $this->assertTrue($fs->exists($target));
        $this->assertSame('hello', $fs->get($target));
        $this->assertTrue($fs->isFile($target));

        $theirs = $this->realFile(self::OTHER, 'sec70-readable.png');
        $this->assertTrue($fs->exists(base_path($theirs)));
        $this->assertNotSame('', $fs->get(base_path($theirs)));
    }

    public function test_a_refusal_is_logged_with_its_reason_and_without_the_path(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = [$event->level, $event->message, $event->context];
        });

        $relative = $this->realFile(self::OTHER, 'sec70-secret-name.png');

        (new StoreBoundFilesystem(self::BOUND))->delete(base_path($relative));

        $refusals = array_values(array_filter($logged, fn ($l) => $l[1] === self::REFUSED));
        $this->assertCount(1, $refusals);
        $this->assertSame('warning', $refusals[0][0]);
        $this->assertSame(['store' => self::BOUND, 'reason' => 'path'], $refusals[0][2]);
        $this->assertStringNotContainsString('sec70-secret-name', json_encode($logged));
        $this->assertStringNotContainsString((string) self::OTHER, json_encode([$refusals[0][1], $refusals[0][2]]), 'The log line names the other store.');
    }

    public function test_an_allowed_delete_is_not_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $mine = $this->realFile(self::BOUND, 'sec70-quiet.png');
        (new StoreBoundFilesystem(self::BOUND))->delete(base_path($mine));

        $this->assertNotContains(self::REFUSED, $logged);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the tenant rule it shares with the write side
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_same_tenant_stores_says_the_store_itself_or_one_its_owner_also_runs_and_nothing_else(): void
    {
        [$owner, $first] = $this->makeTenant();
        [$owner, $first] = $this->rekey($owner, $first, self::STORE_A);
        $second = $this->makeStore($owner);
        DB::table('stores')->where('id', $second->id)->update(['id' => self::STORE_B]);
        (new Store)->flushCache();
        [$stranger, $strangerStore] = $this->makeTenant();
        [, $strangerStore] = $this->rekey($stranger, $strangerStore, self::OTHER);
        $rows = [
            'the store itself' => [self::STORE_A, self::STORE_A, true],
            'another store of the same owner' => [self::STORE_A, self::STORE_B, true],
            'the same, the other way round' => [self::STORE_B, self::STORE_A, true],
            'another owner\'s store' => [self::STORE_A, self::OTHER, false],
            'a string id' => ['' . self::STORE_A, self::STORE_B, true],
            'no row store' => [null, self::STORE_A, false],
            'no saver store' => [self::STORE_A, null, false],
            'store 0 and store 0' => [0, 0, false],
            'a negative store' => [-1, -1, false],
            'store 0 and a real one' => [0, self::STORE_A, false],
            // a store that is not there has no owner (`stores.created_by` is NOT NULL in the migrations, so an ownerless row cannot be built here, but the
            // production table is not the migration: the rule has to read "no owner" as "nobody's")
            'a store that does not exist, against a real one' => [999999999, self::STORE_A, false],
            'a real one against a store that does not exist' => [self::STORE_A, 999999999, false],
            'two stores that do not exist' => [999999998, 999999999, false],
        ];

        foreach ($rows as $what => [$a, $b, $expected]) {
            $this->assertSame($expected, StoredPathPolicy::sameTenantStores($a, $b), $what);
        }
    }

    /** `uploads/007/x` is a folder no store owns: the owner lookup casts it to 7, which is not what the folder is called (BYP-06). */
    public function test_a_folder_is_a_stores_only_in_its_canonical_spelling(): void
    {
        $this->assertTrue(StoredPathPolicy::isSafe('uploads/7/a.png', 7));
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/007/a.png', 7));
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/07/a.png', 7));
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/uploads/007/a.png', 7), 'the gallery\'s doubled uploads/ is no way round it');
    }

    /** A path under store 0's folder is no store's: `uploads/0/x` named by store 0 was accepted by the first form (BYP-06). */
    public function test_is_safe_does_not_hand_store_zero_the_folder_called_zero(): void
    {
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/0/a.png', 0));
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/0/a.png', '0'));
        $this->assertFalse(StoredPathPolicy::isSafe('uploads/1/a.png', 0));
    }
}
