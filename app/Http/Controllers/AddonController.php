<?php

namespace App\Http\Controllers;

use App\Http\Requests\InstallAddonRequest;

use App\Models\Utility;
use App\Models\Store;
use App\Models\Addon;
use Illuminate\Http\Request;
use ZipArchive;
use File;

class AddonController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $addon_themes = Addon::get();
            $theme = Utility::BuyMoreTheme();
            return view('addon.index', compact('theme', 'addon_themes'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('addon.create');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        // SEC-F5. No authorization at all, and $id was interpolated straight
        // into a recursive directory delete, so a traversal id removed arbitrary
        // directories. index() at :21 is already 'super admin'-only; the
        // destructive method was not.
        $this->authorize('manage-platform-addons');

        $safeId = InstallAddonRequest::safeDirectoryName((string) $id);

        if ($safeId === '') {
            return redirect()->back()->with('error', __('Invalid theme identifier.'));
        }

        $theme = Addon::where('theme_id', $id)->firstOrFail();
        $theme->delete();

        File::deleteDirectory(base_path('themes/' . $safeId));

        return redirect()->back()->with('success', __('Theme Deleted Successfully!'));
    }

    public function ThemeInstall(InstallAddonRequest $request)
    {
        // SEC-F5. This method extracted an attacker-supplied archive into the
        // running application with NO authorization. The authorize() call is
        // repeated here deliberately rather than relying on the route group
        // alone -- relying on grouping alone is exactly what failed in SEC-F2.
        $this->authorize('manage-platform-addons');

        $uploadedPath = $request->file('file')->getRealPath();

        if ($reason = InstallAddonRequest::archiveRejectionReason($uploadedPath)) {
            return redirect()->back()->with('error', $reason);
        }

        $zip = new ZipArchive;
        try {
            $res = $zip->open($uploadedPath);
        } catch (\Exception $e) {
            return Utility::error(['message' => $e->getMessage()]);
        }
        if ($res === TRUE)
        {
            /*
             * THE ARCHIVE IS NOT EXTRACTED INTO THE THEMES ROOT. That line was here:
             *
             *     $zip->extractTo(base_path('themes/'));
             *
             * and it wrote every entry at the path the ARCHIVE chose, relative to `themes/`. The
             * entry names are validated first -- `InstallAddonRequest::archiveRejectionReason()` runs
             * above and refuses absolute paths, drive letters, `..`, `../`, `/../` and `/..`, refuses
             * configuration files by name, and enforces an extension allowlist -- so nothing could
             * escape `themes/`. What nothing checked was whether the archive targeted a directory
             * that ALREADY HELD AN INSTALLED THEME: an archive whose entries read `greentic/...`
             * replaced the installed greentic theme's files in place, and every store on that theme
             * then rendered the replacement.
             *
             * IT WAS ALSO DEAD FOR THE INSTALL ITSELF, which is what makes the fix one deletion
             * rather than a redesign. Read the rest of this method: the archive is extracted a SECOND
             * time, into `themes/tmp_<uniqid>`, and it is the TEMP copy everything downstream uses --
             * `scandir($tempPath)` finds the root folder, `moveExtractedFiles($tempPath, ...)` moves
             * the files, and the destination is `$extractPath`, derived from the uploaded FILENAME
             * through `safeDirectoryName()`. Nothing between the two extractions read a single byte
             * the first one wrote. So the first extraction contributed nothing to the outcome and
             * everything to the side effect.
             *
             * SEVERITY, STATED HONESTLY. `manage-platform-addons` is asserted twice -- in
             * `InstallAddonRequest::authorize()` and again at the top of this method, deliberately,
             * because SEC-F5 records that relying on the route group alone is what failed in SEC-F2.
             * So this is operator-reachable and NOT a privilege escalation: the same disposition as
             * the module-removal door. It is worth closing because one operator action should add a
             * theme, not silently replace a different one.
             *
             * AND A CORRECTION TO THE PHASE 06 SWEEP, which recorded this as "theme installation
             * extracts before validating". That is refuted: validation runs first and is substantial.
             * The sweep also called out that uploaded PHP renders -- true, and not a defect:
             * `ALLOWED_ENTRY_EXTENSIONS` contains `php` and `blade.php` because a theme IS Blade
             * code, so installing a theme means installing code by design.
             */

            // Get the file name without extension
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);

            /*
             * AN ARCHIVE MUST NOT BE ABLE TO PICK THE THEMES ROOT AS ITS TARGET.
             *
             * Found by independent review of the extraction fix in `039d17b4`, which closed the
             * one-request overwrite and left this. `safeDirectoryName()` strips everything outside
             * `[A-Za-z0-9_-]` and RETURNS THE EMPTY STRING when nothing is left. Measured:
             *
             *   '....zip'     -> basename '...'       -> ''
             *   '@@@.zip'     -> basename '@@@'       -> ''
             *   'العربية.zip' -> basename 'العربية'   -> ''
             *
             * The Arabic case is why this is not only an attack shape: this product is bilingual
             * and a theme zip named in Arabic is an ordinary upload. With an empty name
             * `$extractPath` became `themes/` itself, `is_dir()` was true so the mkdir below was
             * skipped, and `moveExtractedFiles()` merged the archive's root folder INTO the themes
             * root -- recursing into existing directories and overwriting files with `rename()`. So
             * an archive containing `greentic/...` replaced the installed greentic theme.
             *
             * BOTH SIBLINGS ALREADY HAD THIS CHECK and this one did not:
             * `ModuleController::install()` ("The uploaded file name is not a valid module name")
             * and `AddonController::destroy()` a few lines above.
             */
            $themeName = InstallAddonRequest::safeDirectoryName($filename);

            if ($themeName === '') {
                $return['status'] = 'error';
                $return['message'] = __('The uploaded file name is not a valid theme name.');

                return response()->json($return);
            }

            // Define the target directory
            $extractPath = base_path('themes/' . $themeName);

            // Ensure the directory exists (create it if it doesn't)
            if (!is_dir($extractPath)) {
                mkdir($extractPath, 0777, true);
                if (function_exists('chmod')) {
                    @chmod($extractPath, 0777); // Set permissions if possible
                    @chmod('themes/', 0777);
                }
            } else {
                // Check if chmod exists
                if (function_exists('chmod')) {
                    @chmod($extractPath, 0777); // Set permissions if possible
                    @chmod('themes/', 0777);
                }
            }

            // After extracting to the temporary directory
            $tempPath = base_path('themes/tmp_' . uniqid());
            if (function_exists('chmod')) {
                @chmod($tempPath, 0777); // Set permissions if possible
            }
            $zip->extractTo($tempPath);
            $zip->close();

            // Determine the root folder name in the zip (if needed)
            $rootFolder = array_diff(scandir($tempPath), ['.', '..']);
            if (empty($rootFolder)) {
                $return['status'] = 'error';
                $return['message'] = __('The zip file is empty or does not contain any valid files.');
                return response()->json($return);
            }

            /*
             * THE ARCHIVE'S ROOT FOLDER IS A DIRECTORY, NOT WHATEVER SORTS FIRST.
             *
             * Also from the review of `039d17b4`, and the one case where that commit made things
             * WORSE rather than better. The final contents of `themes/` used to be the UNION of the
             * deleted first extraction and this move; the move alone is partial, because
             * `$rootFolderName` was `scandir()`'s alphabetically first entry. Measured on a
             * directory holding `__MACOSX`, `greentic` and `.DS_Store`:
             *
             *   [0] => .DS_Store    [1] => __MACOSX    [2] => greentic
             *
             * `.` is 0x2E and `_` is 0x5F, both below lowercase `a` at 0x61, and every shipped
             * theme is named in lowercase. So for any Finder-built zip the move took the sidecar and
             * the real theme -- previously saved by the extraction that is now gone -- stayed in the
             * temp tree and was deleted with it: `themes/<name>` held the sidecar's contents, the
             * `Addon` row was still written with `status = 1`, and the theme was broken.
             *
             * And when the first entry is a FILE, `moveExtractedFiles()` pointed `$source` at it;
             * `scandir()` on a file returns `false`, which `array_diff()` rejects as a TypeError on
             * PHP 8.3 -- an HTTP 500 leaving `themes/tmp_<uniqid>` behind. That half is
             * pre-existing, not introduced, and is closed by the same change.
             *
             * The fallback mirrors `ModuleController::install()`, which already has the second half
             * of this and not the first.
             */
            $candidates = array_values(array_filter(
                $rootFolder,
                static fn ($entry) => $entry !== '__MACOSX' && ! str_starts_with((string) $entry, '.')
            ));

            $rootFolderName = $candidates[0] ?? array_values($rootFolder)[0];

            if (! is_dir($tempPath . DIRECTORY_SEPARATOR . $rootFolderName)) {
                $rootFolderName = null;
            }

            // Move files to the target directory
            $this->moveExtractedFiles($tempPath, $extractPath, $rootFolderName);

            // Remove the temporary directory
            $this->deleteDirectory($tempPath);
            
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            $filename        = pathinfo($filenameWithExt, PATHINFO_FILENAME);

            $Addon               = new Addon();
            $Addon->theme_id     = $filename;
            $Addon->status       = '1';
            $Addon->save();

            $return['status'] = 'success';
            $return['message'] = __('Install successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('oops something went wrong!!');
            return response()->json($return);
        }
        $return['status'] = 'error';
        $return['message'] = __('oops something went wrong!!');
        return response()->json($return);
    }

    public function ThemeEnable(Request $request)
    {
        // SEC-F5. Activating a theme changes the code the application renders,
        // so it is a platform-operator action like install and delete.
        $this->authorize('manage-platform-addons');

        $theme = Addon::where('theme_id', $request->name)->first();
        if (!empty($theme)) {
            if ($theme->status == '0') {
                $theme->status = '1';
                $theme->save();
                return redirect()->back()->with('success', __('Theme Enable Successfully!'));
            } else {
                $theme->status = '0';
                $theme->save();
                return redirect()->back()->with('success', __('Theme Disable Successfully!'));
            }
        } else {
            return redirect()->back()->with('error', __('oops something wren wrong!'));
        }
    }

    public function AddonApps(Request $request)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $apps = Utility::BuyMoreTheme();

            return view('addon.apps', compact('apps'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    /**
     * Move files from one directory to another.
     *
     * @param string $source
     * @param string $destination
     */
    private function moveExtractedFiles($source, $destination, $filename = null)
    {
        // Adjust the source directory if a root folder (e.g., $filename) exists in the zip
        if ($filename) {
            $source = $source . DIRECTORY_SEPARATOR . $filename;
        }

        $files = array_diff(scandir($source), ['.', '..']);
        foreach ($files as $file) {
            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $destPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                // Recursively move subdirectories
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0777, true);
                }
                // Check if chmod exists
                if (function_exists('chmod')) {
                    @chmod($destPath, 0777); // Set permissions if possible
                }
                $this->moveExtractedFiles($srcPath, $destPath);
            } else {
                // Move file
                rename($srcPath, $destPath);
                // Check if chmod exists
                if (function_exists('chmod')) {
                    @chmod($destPath, 0777); // Set permissions if possible
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
