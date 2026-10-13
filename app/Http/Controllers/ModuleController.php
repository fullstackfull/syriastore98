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
     *     only screen that posts here (resources/views/module/index.blade.php:218) and because
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
            $module = Module::find($moduleName);
            if($module) {
                $module->disable();
                Permission::where('module',$moduleName)->delete();
                AddOnManager::where('name',$moduleName)->delete();
                // Remove the temporary directory
                $this->deleteDirectory('packages/workdo/' . $moduleName);
                // Sidebar Performance Changes
                sideMenuCacheForget('all');
                return redirect()->back()->with('success', __('Module delete successfully!'));
            } else {
                return redirect()->back()->with('error', __('Oops something went wrong!'));
            }
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
            
            // Remove existing directory if it exists
            if (is_dir($extractPath)) {
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
            $addon = AddOnManager::where('module', $fileName)->first();
            if (empty($addon)) {
                $addon = new AddOnManager;
                $addon->module = $data['name'] ?? $fileName;
                $addon->name = $data['alias'] ?? $fileName;
                $addon->monthly_price = 0;
                $addon->yearly_price = 0;
                $addon->is_enable = 0;
                $addon->is_display = $data['display'] ?? 1;
                $addon->package_name = $data['package_name'] ?? $fileName;
                $addon->save();
            }
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
