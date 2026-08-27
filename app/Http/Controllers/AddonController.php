<?php

namespace App\Http\Controllers;

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
        $theme = Addon::where('theme_id', $id)->first();
        $theme->delete();
        File::deleteDirectory('themes/' . $id);
        return redirect()->back()->with('success', __('Theme Deleted Successfully!'));
    }

    public function ThemeInstall(Request $request)
    {

        $zip = new ZipArchive;
        try {
            $res = $zip->open($request->file);
        } catch (\Exception $e) {
            return Utility::error(['message' => $e->getMessage()]);
        }
        if ($res === TRUE)
        {
            $zip->extractTo('themes/');
            // Get the file name without extension
            $filenameWithExt = $request->file('file')->getClientOriginalName();
            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);

            // Define the target directory
            $extractPath = 'themes/' . $filename;

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
            $tempPath = 'themes/tmp_' . uniqid();
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

            $rootFolderName = array_values($rootFolder)[0]; // Get the first folder name in the zip

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
