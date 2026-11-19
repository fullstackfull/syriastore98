<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SignInImageDeleteGuard;
use App\Models\AddOnManager;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
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
 * after a merchant saves the form with a new image. App\Http\Middleware\SignInImageDeleteGuard swaps the `File` facade's root for a
 * StoreBoundFilesystem for the lifetime of a POST to those two routes; the filesystem carries out a delete under uploads/ only for the
 * signed-in user's own store (or one its owner also runs).
 *
 * THE MODULES ARE LOADED THROUGH THE PRODUCTION LOADER (an `add_on_managers` row): no WorkDo module loads in any test run otherwise, so a test
 * that "passes" against these routes without it never reached them. ORACLE: the file on the REAL filesystem after the request
 * (`File::delete` is not the faked `theme` disk) -- read with file_exists().
 *
 * `uploads/` IS A TRACKED DIRECTORY (see OperatorRootedStoredUploadTest): real folders live under it, so every store here is re-keyed to an id no
 * real folder uses, the ids differ from that class's so the two cannot collide, setUp REFUSES to adopt a folder that already exists, every file
 * this test writes carries `sec70` in its name, and teardown removes only the folders named here.
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
    private const FIXTURE_STORE_IDS = [self::STORE_A, self::STORE_B, self::BOUND, self::OTHER, self::SIBLING];

    /** @var list<string> single files this test created outside the fixture folders (removed afterwards) */
    private array $files = [];

    private bool $createdUploadsRoot = false;
    private bool $fixtureFoldersAreOurs = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createdUploadsRoot = ! is_dir(base_path('uploads'));
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
        foreach ($this->files as $file) {
            @unlink($file);
        }

        // A FRESH filesystem, not the `File` facade: a mutant (or a defect) that leaves the facade bound would make `deleteDirectory()` -- which
        // removes each file through delete() -- refuse to remove this test's own fixture files, and the next run would refuse to start on the
        // folders left behind, so every later case would look like a failure for the wrong reason (it did, once: the first run of the mutants).
        $files = new \Illuminate\Filesystem\Filesystem();
        foreach ($this->fixtureFoldersAreOurs ? self::FIXTURE_STORE_IDS : [] as $id) {
            foreach ($this->foldersOf($id) as $folder) {
                $files->deleteDirectory($folder);
            }
        }

        // the two folders the path matrix writes into that are not a store's; `rmdir` removes an EMPTY directory only
        foreach (['uploads/sec70-platform', 'uploads/sec70-folder'] as $folder) {
            @rmdir(base_path($folder));
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

    private function row(string $name, string $value, int $storeId, int $createdBy): Setting
    {
        // Eloquent, not DB::table(): Setting is Cachable and a raw write leaves the handler's read serving the row it had
        return Setting::create(['name' => $name, 'value' => $value, 'store_id' => $storeId, 'created_by' => $createdBy]);
    }

    private function saveFacebook(User $merchant)
    {
        return $this->actingAs($merchant)->post('/facebooklogin-setting', [
            'facebook_setting_enabled' => 'on', 'facebook_client_id' => 'client', 'facebook_client_secret' => 'secret',
            'facebook_callback' => 'https://example.test/auth/facebook/callback', 'facebook_sign_in_image' => $this->upload(),
        ]);
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

    // ---------------------------------------------------------------------------------------------------------------------------
    // the defect, through the real routes
    // ---------------------------------------------------------------------------------------------------------------------------

    /** THE FIRST TENANT'S IMAGE IS DELETED BY THE SECOND TENANT'S SAVE: the oldest row of that name is the first tenant's. */
    public function test_a_second_tenants_facebook_save_does_not_delete_the_first_tenants_image(): void
    {
        [$a, $storeA, $b] = $this->twoTenants();
        $mine = $this->realFile((int) $storeA->id, 'sec70-a-facebook.png');
        $this->row('facebook_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);

        $this->saveFacebook($b);

        $this->assertFileExists(base_path($mine), 'Tenant B\'s save of the Facebook sign-in settings deleted the file tenant A\'s row names.');
        $this->assertSame(1, Setting::where('name', 'facebook_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the request reached the handler and saved tenant B\'s own row.');
    }

    public function test_a_second_tenants_linkedin_save_does_not_delete_the_first_tenants_image(): void
    {
        [$a, $storeA, $b] = $this->twoTenants();
        $mine = $this->realFile((int) $storeA->id, 'sec70-a-linkedin.png');
        $this->row('linkedin_sign_in_image', $mine, (int) $storeA->id, (int) $a->id);

        $this->saveLinkedIn($b);

        $this->assertFileExists(base_path($mine), 'Tenant B\'s save of the LinkedIn sign-in settings deleted the file tenant A\'s row names.');
        $this->assertSame(1, Setting::where('name', 'linkedin_sign_in_image')->where('store_id', $b->current_store)->count(), 'Premise: the request reached the handler and saved tenant B\'s own row.');
    }

    /** A ROW WRITTEN TO POINT ELSEWHERE: the Store Setting handlers take every posted name into `settings` for the caller's store. */
    public function test_a_row_that_names_another_tenants_file_does_not_get_it_deleted(): void
    {
        [$a, $storeA, $b, $storeB] = $this->twoTenants();
        $victim = $this->realFile((int) $storeA->id, 'sec70-victim.png');
        $this->row('facebook_sign_in_image', $victim, (int) $storeB->id, (int) $b->id);      // B's own row, naming A's file; no other row exists, so it is the first

        $this->saveFacebook($b);

        $this->assertFileExists(base_path($victim), 'A merchant who wrote their own sign-in image row got another tenant\'s file deleted.');
    }

    /** THE CONTROL THE FIX MUST NOT BREAK: a tenant that is alone still replaces its own previous image. */
    public function test_a_tenants_own_previous_image_is_still_deleted_when_it_saves_a_new_one(): void
    {
        [, , $b, $storeB] = $this->twoTenants();
        $old = $this->realFile((int) $storeB->id, 'sec70-own-old.png');
        $this->row('facebook_sign_in_image', $old, (int) $storeB->id, (int) $b->id);

        $this->saveFacebook($b);

        $this->assertFileDoesNotExist(base_path($old), 'Premise: the handler replaces the signed-in tenant\'s own previous image (the control). The guard refused a delete in the tenant\'s own folder.');
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

    // ---------------------------------------------------------------------------------------------------------------------------
    // the middleware
    // ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_guard_is_on_the_web_group_so_the_modules_routes_pass_through_it(): void
    {
        $this->assertContains(SignInImageDeleteGuard::class, app('router')->getMiddlewareGroups()['web'] ?? []);
    }

    public function test_the_guard_names_the_two_routes_the_vendored_handlers_are_registered_under(): void
    {
        $this->assertSame(['facebooklogin.settings', 'linkedinlogin.settings'], SignInImageDeleteGuard::ROUTES);
    }

    private function requestToRoute(string $name, string $method = 'POST'): Request
    {
        $request = Request::create('/x', $method);
        $route = (new Route([$method], '/x', fn () => null))->name($name);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    public function test_the_file_facade_is_put_back_after_a_request_to_a_guarded_route_whatever_the_handler_does(): void
    {
        [$a] = $this->makeTenant();
        $this->actingAs($a);
        $original = File::getFacadeRoot();
        $guard = new SignInImageDeleteGuard();

        $inside = null;
        $guard->handle($this->requestToRoute('facebooklogin.settings'), function () use (&$inside) {
            $inside = File::getFacadeRoot();

            return response('ok');
        });

        $this->assertInstanceOf(StoreBoundFilesystem::class, $inside, 'Premise: inside a guarded route the File facade is store-bound.');
        $this->assertSame($original, File::getFacadeRoot(), 'The File facade was not put back after a normal request.');

        try {
            $guard->handle($this->requestToRoute('linkedinlogin.settings'), fn () => throw new \RuntimeException('the handler failed'));
            $this->fail('Premise: the handler\'s exception propagates.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($original, File::getFacadeRoot(), 'The File facade was not put back after a handler that threw.');
    }

    public function test_any_other_route_or_method_is_not_touched(): void
    {
        [$a] = $this->makeTenant();
        $this->actingAs($a);
        $original = File::getFacadeRoot();
        $guard = new SignInImageDeleteGuard();

        foreach ([['dashboard', 'POST'], ['facebooklogin.settings', 'GET'], [null, 'POST']] as [$name, $method]) {
            $seen = null;
            $request = $name === null ? Request::create('/x', $method) : $this->requestToRoute($name, $method);
            $guard->handle($request, function () use (&$seen) {
                $seen = File::getFacadeRoot();

                return response('ok');
            });

            $this->assertSame($original, $seen, "A {$method} to " . ($name ?? 'no route') . ' got a store-bound File facade.');
        }
    }

    /** An unknown store owns nothing: `0`, not `null` (isSafe(path, null) means "no store to check" and allows any uploads path). */
    public function test_a_request_with_no_signed_in_user_is_bound_to_a_store_that_owns_nothing(): void
    {
        $victim = $this->realFile(self::OTHER, 'sec70-nobody.png');
        $guard = new SignInImageDeleteGuard();
        $answer = null;

        $guard->handle($this->requestToRoute('facebooklogin.settings'), function () use (&$answer, $victim) {
            $answer = File::delete(base_path($victim));

            return response('ok');
        });

        $this->assertFalse($answer, 'Premise: the delete was refused.');
        $this->assertFileExists(base_path($victim), 'A request with nobody signed in deleted an upload.');
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the filesystem
    // ---------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: bool}> a path relative to the repository, and whether a BOUND-store filesystem may delete it */
    public static function thePaths(): array
    {
        $own = self::BOUND;
        $other = self::OTHER;

        return [
            'its own folder' => ["uploads/{$own}/sec70-a.png", true],
            'its own folder, the gallery\'s doubled uploads/' => ["uploads/uploads/{$own}/sec70-a.png", true],
            'another store\'s folder' => ["uploads/{$other}/sec70-a.png", false],
            'another store\'s folder, the gallery\'s doubled uploads/' => ["uploads/uploads/{$other}/sec70-a.png", false],
            'a platform folder that no store owns' => ['uploads/sec70-platform/sec70-a.png', false],
            'a folder of its own, no store id' => ['uploads/sec70-folder/sec70-a.png', false],
            'a walk from its own folder into another\'s' => ["uploads/{$own}/../{$other}/sec70-a.png", false],
            'a walk out of uploads' => ["uploads/{$own}/../../storage/framework/testing/sec70-outside.txt", false],
            'a walk into uploads from outside it' => ["storage/../uploads/{$other}/sec70-a.png", false],
            'a walk into ITS OWN folder from outside' => ["storage/../uploads/{$own}/sec70-a.png", true],
            'public/uploads, another store' => ["public/uploads/{$other}/sec70-a.png", false],
            'public/uploads, its own' => ["public/uploads/{$own}/sec70-a.png", true],
            'storage/uploads, another store' => ["storage/uploads/{$other}/sec70-a.png", false],
            'the framework\'s own files' => ['storage/framework/testing/sec70-framework.txt', true],
            'a file at the root' => ['sec70-root.txt', true],
        ];
    }

    #[DataProvider('thePaths')]
    public function test_a_store_bound_filesystem_deletes_only_what_the_store_may(string $relative, bool $allowed): void
    {
        // a real file for the path as the filesystem will resolve it, so "refused" and "nothing there" cannot be mistaken for each other
        $resolved = $this->resolve(base_path($relative));
        $this->assertStringContainsString('sec70', $resolved, 'Premise: only a file this test names is ever written.');
        File::ensureDirectoryExists(dirname($resolved));

        // and every directory the path walks THROUGH before a `..` must exist, or the operating system cannot resolve it and "refused" and
        // "could not be resolved" look the same (the first run of the mutants showed it: a filesystem that judged only the resolved path
        // passed every case with a `..` in it because `unlink()` failed on a path through a directory that was not there)
        $segments = explode('/', $relative);
        $firstWalk = array_search('..', $segments, true);
        if ($firstWalk !== false && $firstWalk > 0) {
            File::ensureDirectoryExists(base_path(implode('/', array_slice($segments, 0, $firstWalk))));
        }

        file_put_contents($resolved, 'x');
        $this->files[] = $resolved;

        $fs = new StoreBoundFilesystem(self::BOUND);
        $answer = $fs->delete(base_path($relative));

        if ($allowed) {
            $this->assertTrue($answer, "A delete of {$relative} was refused.");
            $this->assertFileDoesNotExist($resolved, "{$relative} was allowed and not deleted.");
        } else {
            $this->assertFalse($answer, "A delete of {$relative} was not reported as refused.");
            $this->assertFileExists($resolved, "{$relative} was deleted by a store-bound filesystem.");
        }
    }

    /** It exists to stop one thing in one place: a path that is not under this application is nobody's upload, and is not judged. */
    public function test_a_path_outside_this_application_is_not_judged(): void
    {
        $outside = (string) tempnam(sys_get_temp_dir(), 'sec70');
        $this->assertFalse(str_starts_with($outside, base_path() . DIRECTORY_SEPARATOR), 'Premise: the file is not under the application.');
        file_put_contents($outside, 'x');
        $this->files[] = $outside;

        $this->assertTrue((new StoreBoundFilesystem(self::BOUND))->delete($outside), 'A path outside the application was refused.');
        $this->assertFileDoesNotExist($outside);
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

    public function test_an_unknown_store_owns_nothing(): void
    {
        $relative = $this->realFile(self::BOUND, 'sec70-unknown.png');

        $this->assertFalse((new StoreBoundFilesystem(0))->delete(base_path($relative)));
        $this->assertFileExists(base_path($relative));
    }

    /** Several paths at once, as an array and as arguments: the allowed ones go, the refused ones stay, and the answer is false. */
    public function test_a_mixed_batch_deletes_what_it_may_and_says_it_refused_something(): void
    {
        $mine = $this->realFile(self::BOUND, 'sec70-mine.png');
        $theirs = $this->realFile(self::OTHER, 'sec70-theirs.png');
        $fs = new StoreBoundFilesystem(self::BOUND);

        $this->assertFalse($fs->delete([base_path($mine), base_path($theirs)]), 'A batch with a refused path answered true.');
        $this->assertFileDoesNotExist(base_path($mine), 'The allowed path of a mixed batch was not deleted.');
        $this->assertFileExists(base_path($theirs), 'The refused path of a mixed batch was deleted.');

        $mine = $this->realFile(self::BOUND, 'sec70-mine-2.png');
        $this->assertFalse($fs->delete(base_path($mine), base_path($theirs)), 'The variadic form with a refused path answered true.');
        $this->assertFileDoesNotExist(base_path($mine));
        $this->assertFileExists(base_path($theirs));
    }

    /** It exists to stop ONE thing: every other call is the real filesystem. */
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

        // and it does not stop a read, or a write, of ANOTHER store's folder: only a delete is bound
        $theirs = $this->realFile(self::OTHER, 'sec70-readable.png');
        $this->assertTrue($fs->exists(base_path($theirs)));
        $this->assertNotSame('', $fs->get(base_path($theirs)));
    }

    public function test_a_refusal_is_logged_without_the_path(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = [$event->level, $event->message, $event->context];
        });

        $relative = $this->realFile(self::OTHER, 'sec70-secret-name.png');

        (new StoreBoundFilesystem(self::BOUND))->delete(base_path($relative));

        $refusals = array_values(array_filter($logged, fn ($l) => $l[1] === 'a delete of an upload outside the current store was refused'));
        $this->assertCount(1, $refusals);
        $this->assertSame('warning', $refusals[0][0]);
        $this->assertSame(['store' => self::BOUND], $refusals[0][2]);
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

        $this->assertNotContains('a delete of an upload outside the current store was refused', $logged);
    }

    // ---------------------------------------------------------------------------------------------------------------------------
    // the path rule it shares with the write side
    // ---------------------------------------------------------------------------------------------------------------------------

    public static function theUploadPaths(): array
    {
        return [
            'uploads' => ['uploads/5/a.png', true],
            'the doubled uploads/' => ['uploads/uploads/5/a.png', true],
            'public/uploads' => ['public/uploads/5/a.png', true],
            'storage/uploads' => ['storage/uploads/5/a.png', true],
            'a walk written inside uploads' => ['uploads/5/../6/a.png', true],
            'the root alone' => ['uploads', false],
            'the root and a slash' => ['uploads/', false],
            'public' => ['public/index.php', false],
            'storage' => ['storage/logs/laravel.log', false],
            'a lookalike' => ['uploads-old/5/a.png', false],
            'a walk that has not resolved yet' => ['storage/../uploads/5/a.png', false],
            'a file at the root' => ['.env', false],
        ];
    }

    #[DataProvider('theUploadPaths')]
    public function test_is_upload_path_says_where_a_path_points_and_nothing_else(string $path, bool $expected): void
    {
        $this->assertSame($expected, \App\Services\StoredPathPolicy::isUploadPath($path), $path);
    }

    /** The path as the operating system resolves it, without needing it to exist (so `a/../b` is `b`). */
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
}
