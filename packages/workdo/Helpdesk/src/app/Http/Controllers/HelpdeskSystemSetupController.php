<?php

namespace Workdo\Helpdesk\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Workdo\Helpdesk\app\Models\HelpdeskCategory;
use Illuminate\Support\Facades\Validator;
use Workdo\Helpdesk\app\Models\HelpdeskStatus;
use Workdo\Helpdesk\DataTables\HelpdeskCategoryData;
use Workdo\Helpdesk\DataTables\HelpdeskStatusData;

class HelpdeskSystemSetupController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(HelpdeskCategoryData $dataTable)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Manage Helpdesk Category')) {
            $helpdeskCategory = HelpdeskCategory::all();
            return $dataTable->render('helpdesk::category.index', compact('helpdeskCategory'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied !!'));
        }
    }

    public function createCategory(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Create Helpdesk Category')) {
            return view('helpdesk::category.create');
        } else {
            return response()->json(['error' => __('Permission Denied !!')], 401);
        }
    }

    public function storeCategory(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Create Helpdesk Category')) {
            $validator = Validator::make(
                $request->all(),
                [
                    'category_name' => 'required',
                    'category_color' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $helpdeskCategory = new HelpdeskCategory();
            $helpdeskCategory->name = $request->category_name;
            $helpdeskCategory->color = $request->category_color;
            $helpdeskCategory->created_by = Auth::user()->id;
            $helpdeskCategory->save();

            return redirect()->back()->with('success', __('Helpdesk Category Save Successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied !!'));
        }
    }

    public function editCategory($id)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Edit Helpdesk Category')) {
            $helpdeskCategory = HelpdeskCategory::where('id', $id)->first();
            if ($helpdeskCategory) {
                return view('helpdesk::category.edit', compact('helpdeskCategory'));
            } else {
                return response()->json(['error' => __('Helpdesk Category Not Found !!')], 401);
            }
        } else {
            return response()->json(['error' => __('Permission Denied !!')], 401);
        }
    }

    public function updateCategory(Request $request, $id)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Edit Helpdesk Category')) {
            $validator = Validator::make(
                $request->all(),
                [
                    'category_name' => 'required',
                    'category_color' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $helpdeskCategory = HelpdeskCategory::where('id', $id)->first();
            if ($helpdeskCategory) {
                $helpdeskCategory->name = $request->category_name;
                $helpdeskCategory->color = $request->category_color;
                $helpdeskCategory->created_by = Auth::user()->id;
                $helpdeskCategory->save();
                return redirect()->route('helpdesk.category')->with('success', __('Helpdesk Category Update Successfully.'));
            } else {
                return redirect()->back()->with('error', __('Helpdesk Category Not Found !!'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied !!'));
        }
    }

    public function deleteCategory($id)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Delete Helpdesk Category')) {
            $helpdeskCategory = HelpdeskCategory::where('id', $id)->first();
            if ($helpdeskCategory) {
                $helpdeskCategory->delete();
                return redirect()->route('helpdesk.category')->with('success', __('Helpdesk Category Delete Successfully.'));
            } else {
                return response()->json(['error' => __('Helpdesk Category Not Found !!')], 401);
            }
        } else {
            return response()->json(['error' => __('Permission Denied !!')], 401);
        }
    }


    // helpdesk status
    public function helpdeskStatusIndex(HelpdeskStatusData $dataTable)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Manage Helpdesk Status')) {
            $helpdeskStatus = HelpdeskStatus::all();
            return $dataTable->render('helpdesk::status.index', compact('helpdeskStatus'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function createStatus(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Create Helpdesk Status')) {
            return view('helpdesk::status.create');
        } else {
            return response()->json(['error' => __('Permission Denied !!')], 401);
        }
    }

    public function storeStatus(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Create Helpdesk Status')) {
            $validator = Validator::make(
                $request->all(),
                [
                    'status_name' => 'required',
                    'status_color' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $helpdeskStatus = new HelpdeskStatus();
            $helpdeskStatus->status_name = $request->status_name;
            $helpdeskStatus->status_color = $request->status_color;
            $helpdeskStatus->created_by = Auth::user()->id;
            $helpdeskStatus->save();

            return redirect()->back()->with('success', __('Helpdesk Status Save Successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied !!'));
        }
    }

    public function editStatus($id)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Edit Helpdesk Status')) {
            $helpdeskStatus = HelpdeskStatus::where('id', $id)->first();
            if ($helpdeskStatus) {
                return view('helpdesk::status.edit', compact('helpdeskStatus'));
            } else {
                return response()->json(['error' => __('Helpdesk Status Not Found !!')], 401);
            }
        } else {
            return response()->json(['error' => __('Permission Denied !!')], 401);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Edit Helpdesk Status')) {
            $validator = Validator::make(
                $request->all(),
                [
                    'status_name' => 'required',
                    'status_color' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $helpdeskStatus = HelpdeskStatus::where('id', $id)->first();
            if ($helpdeskStatus) {
                $helpdeskStatus->status_name = $request->status_name;
                $helpdeskStatus->status_color = $request->status_color;
                $helpdeskStatus->created_by = Auth::user()->id;
                $helpdeskStatus->save();
                return redirect()->route('helpdesk.status')->with('success', __('Helpdesk Status Update Successfully.'));
            } else {
                return redirect()->back()->with('error', __('Helpdesk Status Not Found !!'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied !!'));
        }
    }

    public function deleteStatus($id)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Delete Helpdesk Status')) {
            $helpdeskStatus = HelpdeskStatus::where('id', $id)->first();
            if ($helpdeskStatus) {
                $helpdeskStatus->delete();
                return redirect()->route('helpdesk.status')->with('success', __('Helpdesk Status Delete Successfully.'));
            } else {
                return response()->json(['error' => __('Helpdesk Status Not Found !!')], 401);
            }
        } else {
            return response()->json(['error' => __('Permission Denied !!')], 401);
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('helpdesk::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('helpdesk::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('helpdesk::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }
}
