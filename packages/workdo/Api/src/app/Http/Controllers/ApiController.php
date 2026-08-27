<?php

namespace Workdo\Api\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class ApiController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user() && auth()->user()->isAbleTo('Manage Api')) {
            $path = base_path("packages/workdo/Api/src/documentation/");
            $fileNames = [];
            $files = File::files($path);

            foreach ($files as $file) {
                $fileNames[] = pathinfo($file, PATHINFO_FILENAME);
            }
            $contents = [];
            foreach ($fileNames as $filename) {
                $contents[$filename] = json_decode(file_get_contents($path . $filename . '.json'));
            }

            $store = Store::find(getCurrentStore());
            $json_path = base_path('packages/workdo/Api/src/json/api_setting.json');
            $page_name = 'api_setting';
            $settings = AppSetting::select('page_name', 'theme_json')
                ->where('page_name', $page_name)
                ->where('store_id', $store->id)
                ->get()
                ->keyBy('page_name');

            if (File::exists($json_path)) {
                $jsonContent = File::get($json_path);
                $data = (object)json_decode($jsonContent);
                if (isset($settings[$page_name])) {
                    $data = (object)json_decode($settings[$page_name]->theme_json);
                }
            } else {
                $data = [];
            }
            return view('api::index', compact('contents', 'fileNames','data'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function ApiSetting(Request $request)
    {
        $store = Store::find(getCurrentStore());
        $path = base_path('packages/workdo/Api/src/json/api_setting.json');
        $page_name = 'api_setting';
        $settings = AppSetting::select('page_name', 'theme_json')
            ->where('page_name', $page_name)
            ->where('store_id', $store->id)
            ->get()
            ->keyBy('page_name');

        if (File::exists($path)) {
            $jsonContent = File::get($path);
            $data = json_decode($jsonContent, true);
            if (isset($settings[$page_name])) {
                $data = json_decode($settings[$page_name]->theme_json, true);
            }
        } else {
            $data = [];
        }
        return view('api::setting.index', compact('data'));
    }

    public function API_page_setting(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        $page_name = $request->section_name ?? ($request->page_name ?? null);
        if (empty($page_name)) {
            return redirect()->back()->with('error', __('Page name not found.'));
        }

        $array = $request->array;
        if ($page_name == 'home_page_web') {
            $array = $request->array;
        }
        $decodedData = json_encode($array, true);
        $new_array = [];
        AppSetting::updateOrCreate(
            ['page_name' => $page_name, 'store_id' => getCurrentStore()], // Where condition
            ['page_name' => $page_name, 'store_id' => getCurrentStore(), 'theme_json' => json_encode($array), 'theme_json_api' => json_encode($new_array)]   // Update or Insert
        );

        return redirect()->back()->with('success', __(ucfirst(str_replace('_', ' ', $page_name)) . ' setting saved successfully.'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('api::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('api::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('api::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
