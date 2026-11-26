<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstallAddonRequest;

use Illuminate\Http\Request;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Artisan;
use App\Models\AddOnManager;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use ZipArchive;
use App\Models\Addon;
use App\Models\Utility;
use App\Models\ModuleReviewRecord;
use App\Support\Modules\ModuleNotRemovable;
use App\Support\Modules\ModuleTrust;
use App\Support\Modules\PackageDeclaration;
use App\Support\Modules\RemovableModule;
use Illuminate\Support\Facades\DB;

class ModuleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Module'))
        {
            $modules = Module::allModules();
            //$modules = [];
            $module_path = Module::getPath();
            //$module_path = null;
            $addon_themes = Addon::get();
            $theme = Utility::BuyMoreTheme();
            
            $addon_tab = session()->get('addon_tab');
            if (empty($addon_tab)) {
                $addon_tab = 'pills-premium-tab';
            }
            return view('module.index',compact('modules','module_path', 'theme', 'addon_themes', 'addon_tab'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * NEW-MODULE-ENABLE-UNGATED-01 -- enable()/disable() was the one module-management method
     * with NO authorization check of any kind.
     *
     * index() above requires 'Manage Module' (:25), remove() requires 'Module remove'
     * (:~210), and module.install carries `can:manage-platform-addons` at
     * routes/web.php:1108. This method, declared at routes/web.php:1104 inside the shared
     * `['auth','xss','setlocate','verified']` group, required only a session -- and what it
     * does is a PLATFORM operation, not a tenant one:
     *
     *   - `add_on_managers.is_enable` is a single global row per module, so flipping it
     *     enables or DISABLES a module for every store on the platform at once;
     *   - it runs `Artisan::call('migrate --path=/packages/workdo/' . $request->name . '/...')`
     *     and `Artisan::call('package:seed ' . $request->name)` with the module name taken
     *     STRAIGHT FROM THE REQUEST;
     *   - it reads `base_path('packages/workdo/' . $request->name . '/module.json')`, again
     *     unvalidated, which is a path traversal out of the packages directory.
     *
     * TWO CONTROLS ARE ADDED, and both are needed:
     *
     *  1. 'Manage Module', WITH AN EXPLICIT SUPER-ADMIN PATH -- the SAME name index() already
     *     requires, chosen because it is the
     *     only screen that posts here (resources/views/module/index.blade.php:241) and because
     *     it is listed in config/superadminaccess.php:10 alongside 'Module Add' and
     *     'Module remove', i.e. module management is platform-operator scope BY THE
     *     APPLICATION'S OWN CONFIGURATION. The name is seeded by Utility::addNewData()
     *     (app/Models/Utility.php:2836 and :3141) -- it is not an invented permission, which
     *     is the mistake that once 403'd the entire finance surface. Anyone who can reach the
     *     form already holds it, so no legitimate workflow loses a capability.
     *
     *     THE SUPER-ADMIN PATH IS NOT COSMETIC AND WAS FOUND BY A FAILING TEST, not by
     *     reasoning: Laratrust's isAbleTo() resolves strictly through the role/permission pivot
     *     tables and has NO operator bypass, so on a database where the permission row has not
     *     been seeded for the operator's role the check denies the PLATFORM OPERATOR -- which
     *     is a lockout, not a defence, and is exactly what happened to the six finance
     *     permissions (see App\Http\Middleware\FinancePermission's docblock). The positive
     *     test test_the_platform_operator_can_still_disable_an_installed_module failed on
     *     precisely this before the type check was added.
     *
     *  2. AN ALLOWLIST ON THE NAME. `Module::find()` NEVER returns empty -- its final else
     *     branch (app/Classes/Module.php:75-88) fabricates a Module for any string, so the
     *     `!empty($module)` test below is not a validation and never was. The name is
     *     therefore checked against the real installed modules, which is exactly what
     *     `Module::has()` does (app/Classes/Module.php:133-136, over the on-disk package
     *     directories), before any Artisan call or file read receives it.
     */
    public function enable(Request $request)
    {
        $actor = auth()->user();
        $isPlatformOperator = $actor
            && isset($actor->type)
            && strtolower(trim((string) $actor->type)) === 'super admin';

        if (!$actor || (!$isPlatformOperator && !$actor->isAbleTo('Manage Module'))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'status' => false,
                    'message' => __('Permission denied.'),
                ], 403);
            }

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (!is_string($request->name) || trim($request->name) === '' || !Module::has($request->name)) {
            return redirect()->back()->with('error', __('Oops something went wrong!'));
        }

        $module = Module::find($request->name);
        if (!empty($module)) {
            // Sidebar Performance Changes
            sideMenuCacheForget('all');
            if ($module->isEnabled()) {
                $module = Module::find($request->name);
                $check_child_module = $this->Check_Child_Module($module);
                if ($check_child_module == true) {
                    $module->disable();
                    Module::moduleCacheForget($request->name);
                    return redirect()->back()->with('success', __('Module Disable Successfully!'));
                } else {
                    $addon = AddOnManager::where('module', $request->name)->first();
    
                    if (empty($addon)) {
                        Artisan::call('migrate --path=/packages/workdo/' . $request->name . '/src/database/migrations');
                        Artisan::call('package:seed ' . $request->name);
    
                        $filePath = base_path('packages/workdo/' . $request->name . '/module.json');
                        $jsonContent = file_get_contents($filePath);
                        $data = json_decode($jsonContent, true);
    
    
                        $addon = new AddOnManager;
                        $addon->module = $data['name'];
                        $addon->name = $data['alias'];
                        $addon->monthly_price = $data['monthly_price'] ?? 0;
                        $addon->yearly_price = $data['monthly_price'] ?? 0;
                        $addon->package_name = $data['package_name'];
                        $addon->save();
                        Module::moduleCacheForget($request->name);
                    }
                    $module = Module::find($request->name);
                    $check_parent_module = $this->Check_Parent_Module($module);
                    if ($check_parent_module['status'] == true) {
                        Artisan::call('migrate --path=/packages/workdo/' . $request->name . '/src/database/migrations');
                        Artisan::call('package:seed ' . $request->name);
                        $module->enable();
                        return redirect()->back()->with('success', __('Module Enable Successfully!'));
                    } else {
                        return redirect()->back()->with('error', __($check_parent_module['msg']));
                    }
                }
            } else {
                $addon = AddOnManager::where('module', $request->name)->first();
                if (empty($addon)) {
                    Artisan::call('migrate --path=/packages/workdo/' . $request->name . '/src/database/migrations');
                    Artisan::call('package:seed ' . $request->name);

                    $filePath = base_path('packages/workdo/' . $request->name . '/module.json');
                    $jsonContent = file_get_contents($filePath);
                    $data = json_decode($jsonContent, true);


                    $addon = new AddOnManager;
                    $addon->module = $data['name'];
                    $addon->name = $data['alias'];
                    $addon->monthly_price = $data['monthly_price'] ?? 0;
                    $addon->yearly_price = $data['monthly_price'] ?? 0;
                    $addon->package_name = $data['package_name'];
                    $addon->save();
                    Module::moduleCacheForget($request->name);
                }
                $module = Module::find($request->name);
                $check_parent_module = $this->Check_Parent_Module($module);
                if ($check_parent_module['status'] == true) {
                    Artisan::call('migrate --path=/packages/workdo/' . $request->name . '/src/database/migrations');
                    Artisan::call('package:seed ' . $request->name);
                    $module->enable();
                    Module::moduleCacheForget($request->name);
                    return redirect()->back()->with('success', __('Module Enable Successfully!'));
                } else {
                    return redirect()->back()->with('error', __($check_parent_module['msg']));
                }
            }
        }
    }

    public function remove($moduleName)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Module remove')) {
            /*
             * THE WHOLE CONTRACT BEFORE THE FIRST WRITE.
             *
             * This replaced `$module = Module::find($moduleName); if ($module) { ... }`, which was
             * not a guard: `find()`'s final `else` returns a populated object for ANY string, so
             * every destructive line below ran for every input the router could deliver. The two
             * reachable losses, and why they are not equally live, are set out in
             * `RemovableModule`'s docblock; the short version is that `Base` is not a module but IS
             * the core permission set, and `permission_role` cascades.
             *
             * `RemovableModule::resolve()` throws unless the identifier is a verified, installed,
             * non-protected module whose directory canonically resolves inside
             * `packages/workdo`. Nothing below it touches the database or the filesystem until it
             * has returned.
             */
            try {
                $removable = RemovableModule::resolve($moduleName);
            } catch (ModuleNotRemovable $refusal) {
                /*
                 * The SAME message the old `else` branch returned, deliberately: a refusal must not
                 * tell the caller which check they tripped. The reason travels in the exception for
                 * a future operator-facing log -- not added here, because this file is covered by
                 * the line-keyed observability inventory and a NEW `Log::` call needs a stated
                 * destination and reader that only a person can give.
                 */
                return redirect()->back()->with('error', __('Oops something went wrong!'));
            }

            Module::find($removable->name)->disable();

            /*
             * The two row deletes together, so a failure in the second cannot leave the first
             * applied. The directory delete stays OUTSIDE and AFTER: a filesystem unlink cannot
             * join a database transaction, and doing it last means a rolled-back delete never
             * leaves a module whose files are gone but whose rows remain.
             *
             * THE SECOND DELETE READ THE WRONG COLUMN AND SO DELETED ALMOST NOTHING. It was
             * `where('name', $moduleName)`, and `add_on_managers` keeps the two apart:
             * `ModuleController` itself writes `$addon->module = $data['name']` and
             * `$addon->name = $data['alias']` at :142-143, :173-174 and :414-415. MEASURED over all
             * 70 vendored manifests: `name` equals the directory for 70/70, while `alias` DIFFERS
             * from it for 62 of 70 ("ProductReview" -> "Product Review", "LandingPage" -> "CMS").
             * So the old predicate matched 8 modules at most, and left an orphan row for the other
             * 62 -- which then blocks re-installation, because :411 finds the module by `module`
             * and skips creation, leaving the re-installed module disabled.
             *
             * THIS IS A BEHAVIOUR CHANGE AND IS MEANT TO BE: the row now really goes. It is in the
             * same direction as the rest of the method (the module's files are being deleted on the
             * next line, so a surviving row describes a module that no longer exists), and
             * `module` is the canonical identifier in this very file -- :423 passes it to
             * `Module::moduleCacheForget()`. Pre-existing, not introduced by the security fix, and
             * found by review rather than by me.
             */
            DB::transaction(function () use ($removable) {
                Permission::where('module', $removable->name)->delete();
                AddOnManager::where('module', $removable->name)->delete();
            });

            // An absolute, canonical, root-confined path -- never a relative string built from input.
            $this->deleteDirectory($removable->directory);

            // Sidebar Performance Changes
            sideMenuCacheForget('all');

            return redirect()->back()->with('success', __('Module delete successfully!'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function Check_Child_Module($module)
    {
        $path =$module->getPath().'/module.json';

        $module_json = json_decode(file_get_contents($path));
        $json=(array)$module_json;
        $status = true;
        if(isset($json['child_module']) && !empty($json['child_module']))
        {
            foreach ($json['child_module'] as $key => $value)
            {
                $child_module = module_is_active($value);
                if($child_module == true)
                {
                    $module = Module::find($value);
                    $module->disable();
                    if($module)
                    {
                        $this->Check_Child_Module($module);
                    }
                }
            }
            return true;
        }
        else
        {
            return true;
        }
    }

    public function Check_Parent_Module($module)
    {
        $path =$module->getPath().'/module.json';
        $json = json_decode(file_get_contents($path), true);
        $data['status'] = true;
        $data['msg'] = '';

        if(isset($json['parent_module']) && !empty($json['parent_module']))
        {
            foreach ($json['parent_module'] as $key => $value) {
                $modules = implode(',',$json['parent_module']);
                $parent_module = module_is_active($value);
                if($parent_module == true)
                {
                    $module = Module::find($value);
                    if($module)
                    {
                         $this->Check_Parent_Module($module);
                    }
                }
                else
                {
                    $data['status'] = false;
                    $data['msg'] = 'please activate this module '.$modules;
                    return $data;
                }
            }
            return $data;
        }
        else
        {
            return $data;
        }
    }

    public function install(InstallAddonRequest $request)
    {
        // SEC-F5. install() checked NOTHING while every other method in this
        // controller is gated (:24 'Manage Module', :127 'Module remove', :300
        // 'Module Add') -- a correct permission already existed and was simply
        // not applied to the method that unpacks an attacker archive into the
        // application. Repeated here rather than trusting the route group alone.
        $this->authorize('manage-platform-addons');

        $zip = new ZipArchive;

        // Was getClientOriginalName() with str_replace('.zip','',...) -- not a
        // suffix match -- interpolated into 'packages/workdo/' . $fileName with
        // no basename(), so a client filename of '../../public/x.zip' escaped
        // the install directory.
        $fileName = InstallAddonRequest::safeDirectoryName(
            $request->file('file')->getClientOriginalName()
        );
        $return = [];

        if ($fileName === '') {
            $return['status'] = 'error';
            $return['message'] = __('The uploaded file name is not a valid module name.');
            return response()->json($return);
        }

        $uploadedPath = $request->file('file')->getRealPath();

        if ($reason = InstallAddonRequest::archiveRejectionReason($uploadedPath)) {
            $return['status'] = 'error';
            $return['message'] = $reason;
            return response()->json($return);
        }

        try {
            $res = $zip->open($uploadedPath);
        } catch (\Exception $e) {
            $return['status'] = 'error';
            $return['message'] = $e->getMessage();
            return response()->json($return);
        }
        if ($res === TRUE) {
            // Create temporary directory
            $tempPath = base_path('packages/workdo/tmp_' . uniqid());
            if (!is_dir($tempPath)) {
                mkdir($tempPath, 0777, true);
            }
            
            // Extract to temporary directory
            $zip->extractTo($tempPath);
            $zip->close();
            // Find the actual module directory in temp
            $tempContents = array_diff(scandir($tempPath), ['.', '..']);
            if (empty($tempContents)) {
                $this->deleteDirectory($tempPath);
                $return['status'] = 'error';
                $return['message'] = __('The zip file is empty or does not contain any valid files.');
                return response()->json($return);
            }
            // Get the first item (should be the module folder)
            $moduleFolder = array_values($tempContents)[0];
            $sourcePath = $tempPath . '/' . $moduleFolder;
            
            // Check if it's a directory, if not, use temp path directly
            if (!is_dir($sourcePath)) {
                $sourcePath = $tempPath;
            }
            // Define final target directory
            $extractPath = base_path('packages/workdo/' . $fileName);

            /*
             * AN UPLOAD MUST NOT SILENTLY REPLACE A DIFFERENT MODULE. MARKET-002.
             *
             * THE DEFECT, MEASURED. `$fileName` comes from the uploaded FILENAME, and the next lines
             * recursively deleted whatever was at that path. So `LandingPage.zip` deleted the shipped
             * LandingPage module -- the CMS that owns `/` and more routes than any other module -- and
             * put the archive's contents there instead, with one HTTP request, no confirmation and no
             * record of what had been there.
             *
             * THIS IS THE DEFECT THE THEME DOOR WAS ALREADY FIXED FOR, and only the theme door was.
             * `PHASE-06-THEME-INSTALL-TARGET.md` and `ThemeInstallDoesNotOverwriteAnotherThemeTest`
             * record "an archive whose entries read `greentic/...` replaced the installed greentic
             * theme's files in place, and every store on that theme then rendered the replacement".
             * The module door is the same shape by a different route: there the ARCHIVE picked the
             * target, here the FILENAME does.
             *
             * IT INTERACTS WITH THE MARKET-004 BOUNDARY, which is why it is closed here rather than
             * left. `ModuleTrust::recordUpload()` below writes a `pending` record for whatever name
             * this install lands under, and `loadDynamicAddons()` then refuses to load it. So without
             * this guard, an upload named after a shipped module would both REPLACE it and make it
             * UNLOADABLE until somebody approved the replacement.
             *
             * WHAT IS STILL ALLOWED: updating a module with an archive that IS that module. The
             * manifest is read from the extracted TEMP copy -- before anything is deleted -- and the
             * install proceeds when the names agree, or when either side declares no name (a module
             * with no manifest cannot be told apart from any other, and refusing there would block
             * re-installing one).
             *
             * SEVERITY, STATED HONESTLY: operator-reachable only. Both the route group and
             * `install()` itself assert `manage-platform-addons`, which is a `users.type` check, so
             * this is self-inflicted rather than an escalation -- the same disposition the theme door
             * and the module-removal door carry. It is worth closing because one operator action
             * should add a module, not silently destroy a different one.
             */
            if (is_dir($extractPath)) {
                $installedName = PackageDeclaration::manifest($extractPath)['name'] ?? null;
                $incomingName = PackageDeclaration::manifest($sourcePath)['name'] ?? null;

                if (is_string($installedName) && is_string($incomingName) && $installedName !== $incomingName) {
                    $this->deleteDirectory($tempPath);

                    $return['status'] = 'error';
                    $return['message'] = __('A different add-on is already installed under that name. Rename the file, or remove the installed add-on first.');

                    return response()->json($return);
                }

                $this->deleteDirectory($extractPath);
            }
            
            // Create target directory
            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0777, true);
            }
            // Move files from source to target
            $this->moveExtractedFiles($sourcePath, $extractPath);
            // Clean up temporary directory
            $this->deleteDirectory($tempPath);
            // Set permissions
            if (function_exists('chmod')) {
                @chmod($extractPath, 0777);
                @chmod('packages/workdo', 0777);
            }
            $filePath = base_path('packages/workdo/' . $fileName . '/module.json');
            $data = [];
            if (file_exists($filePath)) {
                $jsonContent = file_get_contents($filePath);
                $data = json_decode($jsonContent, true) ?? [];
            }
            /*
             * A NAME THE REVIEW REGISTER CANNOT KEY IS REFUSED BEFORE THE ROW IS WRITTEN.
             *
             * `add_on_managers.module` is VARCHAR(255) and takes `$data['name'] ?? $fileName` -- the
             * archive's OWN manifest. `module_review_records.subject` is VARCHAR(191). So a name
             * between 192 and 255 characters installed fine and failed the register's insert, and
             * `ModuleTrust::recordUpload()` cannot undo an install whose files are already on disk:
             * **no record means trusted**, which would have been a bypass of the whole MARKET-004
             * boundary by nothing more than a long filename. Found by review of this change.
             *
             * REFUSING HERE IS SAFE, and that is why this is the chosen place: the loader plucks
             * `add_on_managers.module`, so files on disk with no row are never loaded. The extracted
             * directory is removed anyway rather than left for somebody to wonder about.
             */
            $moduleName = (string) ($data['name'] ?? $fileName);

            if (! ModuleTrust::nameFitsTheRegister($moduleName)) {
                $this->deleteDirectory($extractPath);

                $return['status'] = 'error';
                $return['message'] = __('The add-on name is too long to be reviewed. Rename the file and try again.');

                return response()->json($return);
            }

            $addon = AddOnManager::where('module', $fileName)->first();
            if (empty($addon)) {
                $addon = new AddOnManager;
                $addon->module = $moduleName;
                $addon->name = $data['alias'] ?? $fileName;
                $addon->monthly_price = 0;
                $addon->yearly_price = 0;
                $addon->is_enable = 0;
                $addon->is_display = $data['display'] ?? 1;
                $addon->package_name = $data['package_name'] ?? $fileName;
                $addon->save();
            }

            /*
             * MARKET-004 / EX-010. THE PACKAGE IS ON DISK AND ITS ROW EXISTS, SO THE NEXT REQUEST
             * WOULD RUN IT. This records that it arrived by upload and has not been reviewed, which
             * is what `PackageServiceProvider::loadDynamicAddons()` reads before registering a
             * provider. Without this row the loader trusts the package, because an absent row means
             * "shipped with the product" -- see `ModuleTrust`'s docblock for why it must.
             *
             * THE SUBJECT IS `$addon->module`, NOT `$fileName`, and the difference is real: the row
             * is written as `$data['name'] ?? $fileName`, so an archive whose `module.json` declares
             * a different `name` than its filename produces a row the loader keys on and a directory
             * it does not. `add_on_managers.module` is what the loader plucks, so it is what the
             * trust register must be keyed on. The DIRECTORY is still `$fileName`, because that is
             * where the bytes went, and that is where the declarations are read from.
             *
             * MARKET-002's review inputs -- declared version, declared dependencies, declared
             * permission names -- are captured here, by STATIC READ of the package's own files.
             * Nothing in the package is executed to obtain them; that is the whole prohibition.
             */
            ModuleTrust::recordUpload(
                (string) $addon->module,
                ModuleReviewRecord::TYPE_MODULE,
                base_path('packages/workdo/' . $fileName),
                auth()->id()
            );

            Module::moduleCacheForget($addon->module);
            $return['status'] = 'success';
            $return['message'] = __('Install successfully');
            return response()->json($return);
        }
        $return['status'] = 'error';
        $return['message'] = __('Something went wrong');
        return response()->json($return);
    }

    public function add(){
        if(auth()->user() && auth()->user()->isAbleTo('Module Add'))
        {
            return view('module.add');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Move files from one directory to another.
     *
     * @param string $source
     * @param string $destination
     */
    private function moveExtractedFiles($source, $destination)
    {
        if (!is_dir($source)) {
            return false;
        }
        $files = array_diff(scandir($source), ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $destPath = $destination . DIRECTORY_SEPARATOR . $file;
            if (is_dir($srcPath)) {
                // Create destination directory if it doesn't exist
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0777, true);
                }
                if (function_exists('chmod')) {
                    @chmod($destPath, 0777);
                }
                // Recursively move subdirectories
                $this->moveExtractedFiles($srcPath, $destPath);
            } else {
                // Move file
                if (file_exists($srcPath)) {
                    rename($srcPath, $destPath);
                    if (function_exists('chmod')) {
                        @chmod($destPath, 0777);
                    }
                }
            }
        }
    }

    /**
     * Delete a directory and its contents.
     *
     * @param string $dirPath
     * @return bool
     */
    private function deleteDirectory($dirPath)
    {
        /*
         * A SYMLINK IS UNLINKED, NEVER RECURSED INTO. Found by independent review of the Phase 06
         * removal fix, and it is the one thing that fix did NOT close.
         *
         * `RemovableModule::confineWithin()` canonicalises the MODULE directory and proves it sits
         * inside `packages/workdo`, which closes the case where the module directory itself is a link
         * pointing out of the tree. It says nothing about a link NESTED inside an accepted module --
         * and this recursion decided with `is_dir($path)`, which FOLLOWS links. So
         * `packages/workdo/Api/x -> /elsewhere` would have had every file under the target `unlink`ed
         * through the link and every directory `rmdir`ed. The link itself then survives, because
         * `rmdir` on a symlink fails ENOTDIR, so the only symptom is a removal that "did not finish".
         *
         * NOT LIVE ON THIS CHECKOUT, AND MEASURED RATHER THAN ASSUMED: `find packages/workdo -type l`
         * returns ZERO across all 70 modules; no first-party code calls `symlink()`; and neither way a
         * module arrives can plant one -- `ZipArchive::extractTo()` writes a symlink entry as a
         * regular FILE containing the target path, and `moveExtractedFiles()` moves with `rename()`,
         * which preserves that. This is hardening against a future vendored tree, not a reachable
         * hole today. It is here because the requirement names "unsafe symlink resolution" by name,
         * because it also covers the three install-path callers, and because it costs two branches.
         *
         * ONE GUARD, AT THE SINGLE ENTRY POINT, AND THAT IS ENOUGH -- which I only know because a
         * mutation said so. The first version of this fix ALSO tested `is_link` on each scanned
         * child, and dropping that second test broke nothing: `is_dir($path)` follows the link, so
         * the recursion re-enters this method with the link itself as `$dirPath` and the check below
         * fires there instead. A link to a FILE never recurses at all -- `is_dir` is false, so
         * `unlink` removes the link and not its target. The child check was therefore code that no
         * test could distinguish from its absence, which is the exact shape this slice was already
         * bitten by once, so it is gone rather than left to rot.
         *
         * THE SAME GUARD CLOSES THE TOCTOU between `confineWithin()`'s `realpath()` and this call,
         * in which the module directory is replaced by a link AFTER it has been proven to be a real
         * directory inside the root. `realpath()` returning false mid-race needs nothing --
         * `confineWithin` throws before any write, and a later disappearance just returns false at
         * the `is_dir` check below.
         */
        if (is_link($dirPath)) {
            return unlink($dirPath);
        }

        if (!is_dir($dirPath)) {
            return false;
        }

        $items = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($items as $item) {
            $path = $dirPath . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dirPath);
    }
}
