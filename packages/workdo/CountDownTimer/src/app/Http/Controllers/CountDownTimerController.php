<?php

namespace Workdo\CountDownTimer\app\Http\Controllers;

use App\Models\Setting;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\CountDownTimer\app\Models\CountDownTimer;
use Carbon\Carbon;
use Yajra\DataTables\Facades\DataTables;

class CountDownTimerController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('count-down-timer::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        $themeLayouts = [
            'theme_layout_1',
            'theme_layout_2',
            'theme_layout_3',
            'theme_layout_4',
            'theme_layout_5'
        ];
        return view('count-down-timer::admin.create', compact('themeLayouts'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        session()->put('app_setting_tab', 'pills-count_down-tab');
        if (\Auth::user() && \Auth::user()->type == 'admin') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'title' => 'required',
                    'start_date' => 'required|date',
                    'start_time' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $startDate = Carbon::parse($request->start_date . ' ' . $request->start_time);
            $now = Carbon::now();
            session()->put('app_setting_tab', $request->app_setting_tab);
            if ($startDate->isPast($now) == false) {
                $totalImageSize = 0;
                if ($request->hasFile('sale_image')) {
                    $totalImageSize += $request->file('sale_image')->getSize();
                }
                if ($request->hasFile('image')) {
                    $totalImageSize += $request->file('image')->getSize();
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }

                $countDown = new CountDownTimer();
                $countDown->title = $request->title;
                $countDown->start_date = $request->start_date;
                $countDown->start_time = $request->start_time;
                $countDown->description = $request->description;
                $countDown->theme_layout = $request->theme_layout;
                $countDown->status = $request->has('status') ? 'on' : 'off';

                if (isset($request->sale_image) && !empty($request->sale_image)) {

                    $dirPath = 'uploads';
                    $appUrlSubdirectory = 'count-down-image';
                    $dirPath .= '/' . $appUrlSubdirectory;
                    if (!file_exists($dirPath)) {
                        mkdir($dirPath, 0777, true);
                        chmod($dirPath, 0777);
                    }

                    if ($result == 1) {
                        $fileName = rand(10, 100) . '_' . time() . "_" . $request->sale_image->getClientOriginalName();
                        $path = Utility::upload_file($request, 'sale_image', $fileName, $dirPath, []);
                        if ($path['flag'] == 1) {
                            $url = $path['url'];
                            $countDown->sale_image = $url;
                        } else {
                            return redirect()->back()->with('error', __($path['msg']));
                        }
                    } else {
                        return redirect()->back()->with('error', $result);
                    }
                }

                if (isset($request->image) && !empty($request->image)) {

                    $dirPath = 'uploads';
                    $appUrlSubdirectory = 'count-down-image';
                    $dirPath .= '/' . $appUrlSubdirectory;
                    if (!file_exists($dirPath)) {
                        mkdir($dirPath, 0777, true);
                        chmod($dirPath, 0777);
                    }

                    if ($result == 1) {
                        $fileName = rand(10, 100) . '_' . time() . "_" . $request->image->getClientOriginalName();
                        $path = Utility::upload_file($request, 'image', $fileName, $dirPath, []);
                        if ($path['flag'] == 1) {
                            $url = $path['url'];
                            $countDown->image = $url;
                        } else {
                            return redirect()->back()->with('error', __($path['msg']));
                        }
                    } else {
                        return redirect()->back()->with('error', $result);
                    }
                }

                $countDown->created_by = \Auth::user()->id;
                $countDown->store_id = getCurrentStore();
                $countDown->save();
                return redirect()->back()->with('success', __('Count-Down successfully created.'));
            } else {
                return redirect()->back()->with('error', __('Please select an upcoming date and time.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('count-down-timer::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $countDown = CountDownTimer::find($id);
        if ($countDown) {
            $themeLayouts = [
                'theme_layout_1',
                'theme_layout_2',
                'theme_layout_3',
                'theme_layout_4',
                'theme_layout_5'
            ];
            return view('count-down-timer::admin.edit', compact('countDown', 'themeLayouts'));
        } else {
            return redirect()->back()->with('error', __('The Count Down Timer detail is not found.'));
        }
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        session()->put('app_setting_tab', 'pills-count_down-tab');
        if (\Auth::user() && \Auth::user()->type == 'admin') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'title' => 'required',
                    'start_date' => 'required|date',
                    'start_time' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            session()->put('app_setting_tab', $request->app_setting_tab);
            $startDate = Carbon::parse($request->start_date . ' ' . $request->start_time);
            $now = Carbon::now();

            if ($startDate->isPast($now) == false) {
                $countDown = CountDownTimer::find($id);
                if ($countDown) {
                    $countDown->title = $request->title;
                    $countDown->start_date = $request->start_date;
                    $countDown->start_time = $request->start_time;
                    $countDown->description = $request->description;
                    $countDown->theme_layout = $request->theme_layout;
                    $countDown->status = $request->has('status') ? 'on' : 'off';

                    $totalImageSize = 0;
                    if ($request->hasFile('sale_image')) {
                        $totalImageSize += $request->file('sale_image')->getSize();
                    }
                    if ($request->hasFile('image')) {
                        $totalImageSize += $request->file('image')->getSize();
                    }
                    $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                    if ($result != 1) {
                        return redirect()->back()->with('error', $result);
                    }

                    if (isset($request->sale_image) && !empty($request->sale_image)) {

                        $dirPath = 'uploads';
                        $appUrlSubdirectory = 'count-down-image';
                        $dirPath .= '/' . $appUrlSubdirectory;
                        if (!file_exists($dirPath)) {
                            mkdir($dirPath, 0777, true);
                            chmod($dirPath, 0777);
                        }

                        $file_path = $countDown->sale_image;
                        
                        if ($result == 1) {
                            if (!empty($file_path) && \File::exists(base_path($file_path))) {
                                Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                            }
                            $fileName = rand(10, 100) . '_' . time() . "_" . $request->sale_image->getClientOriginalName();
                            $path = Utility::upload_file($request, 'sale_image', $fileName, $dirPath, []);
                            if ($path['flag'] == 1) {
                                $url = $path['url'];
                                $countDown->sale_image = $url;
                            } else {
                                return redirect()->back()->with('error', __($path['msg']));
                            }
                        } else {
                            return redirect()->back()->with('error', $result);
                        }
                    }

                    if (isset($request->image) && !empty($request->image)) {

                        $dirPath = 'uploads';
                        $appUrlSubdirectory = 'count-down-image';
                        $dirPath .= '/' . $appUrlSubdirectory;
                        if (!file_exists($dirPath)) {
                            mkdir($dirPath, 0777, true);
                            chmod($dirPath, 0777);
                        }

                        $file_path = $countDown->image;
                        
                        if ($result == 1) {
                            if (!empty($file_path) && \File::exists(base_path($file_path))) {
                                Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                            }
                            $fileName = rand(10, 100) . '_' . time() . "_" . $request->image->getClientOriginalName();
                            $path = Utility::upload_file($request, 'image', $fileName, $dirPath, []);
                            if ($path['flag'] == 1) {
                                $url = $path['url'];
                                $countDown->image = $url;
                            } else {
                                return redirect()->back()->with('error', __($path['msg']));
                            }
                        } else {
                            return redirect()->back()->with('error', $result);
                        }
                    }

                    $countDown->created_by = \Auth::user()->id;
                    $countDown->store_id = getCurrentStore();
                    $countDown->save();
                    return redirect()->back()->with('success', __('Count-Down successfully updated.'));
                } else {
                    return redirect()->back()->with('error', __('The Count Down Timer detail is not found.'));
                }
            } else {
                return redirect()->back()->with('error', __('Please select an upcoming date and time.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        session()->put('app_setting_tab', 'pills-count_down-tab');
        $countDown = CountDownTimer::find($id);
        if (!empty($countDown->sale_image) && \File::exists(base_path($countDown->sale_image))) {
            Utility::changeStorageLimit(\Auth::user()->creatorId(), $countDown->sale_image);
        }
        if (!empty($countDown->image) && \File::exists(base_path($countDown->image))) {
            Utility::changeStorageLimit(\Auth::user()->creatorId(), $countDown->image);
        }
        $countDown->delete();
        if ($countDown == true) {
            return redirect()->back()->with('success', __('Count-Down deleted successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function countDownSettings(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'start_countdown_days' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        session()->put('app_setting_tab', $request->app_setting_tab);
        $post = $request->all();
        unset($post['_token']);

        $post['start_countdown_days'] = $request->start_countdown_days;
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => getCurrentStore()
                ],
                [
                    'value' => $data,
                    'name' => $key,
                    'store_id' => getCurrentStore(),
                    'created_by' => \Auth::user()->id,
                ]
            );
        }

        return redirect()->back()->with('success', __('Count-Down days settings successfully saved.'));
    }

    public function updateStatus(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        $countDownId = $request->input('countDownId');
        $isActivated = $request->input('isActivated');
        $allCountDown = CountDownTimer::where('store_id', getCurrentStore())->where('status', 'on')->get();
        $countDown = CountDownTimer::findOrFail($countDownId);
        $input = $request->all();

        if ($isActivated == 'true') {
            if ($allCountDown->count() == 0) {
                $countDown->status = 'on';
                $input['message'] = __('Status Updated Successfully.');
            } else {
                $input['error_message'] = __('You can only enable a single count-down. Please disable other count-down then you can enable this count-down.');
            }
        } else {
            $countDown->status = 'off';
            $input['message'] = __('Status Updated Successfully.');
        }
        $countDown->save();

        return response()->json($input);
    }

    public function getCountDownTimers(Request $request)
    {
        if ($request->ajax()) {
            $countDownEntrys = CountDownTimer::where('store_id', getCurrentStore())
                ->orderBy('id', 'desc');

            return DataTables::of($countDownEntrys)
                ->editColumn('theme_layout', function ($row) {
                    return '<img src="' . asset('packages/workdo/CountDownTimer/src/resources/assets/layout-img/theme_layout_' . $row->theme_layout . '.png') . '" width="50%"/>';
                })
                ->editColumn('status', function ($row) {
                    return '<div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input countDown-toggle"
                            data-countdown-id="' . $row->id . '" ' . ($row->status == 'on' ? 'checked' : '') . ' />
                        </div>';
                })
                ->addColumn('action', function (CountDownTimer $countDownEntry) {
                    return view('count-down-timer::admin.action', compact('countDownEntry'));
                })
                ->rawColumns(['theme_layout', 'status', 'action'])
                ->make(true);
        }
        return view('count-down-timer::admin.countDownSettingDetails');
    }
}
