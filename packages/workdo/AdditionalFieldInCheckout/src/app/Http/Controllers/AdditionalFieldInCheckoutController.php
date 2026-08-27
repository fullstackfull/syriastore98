<?php

namespace Workdo\AdditionalFieldInCheckout\app\Http\Controllers;

use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\AdditionalFieldInCheckout\app\Models\AdditionalField;
use Workdo\AdditionalFieldInCheckout\DataTables\AdditionalFieldInCheckoutData;
use Illuminate\Support\Facades\Cache;

class AdditionalFieldInCheckoutController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(AdditionalFieldInCheckoutData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user() && auth()->user()->isAbleTo('Manage AdditionalField')) {
            $fields = AdditionalField::where('store_id', getCurrentStore())->get();
            return $dataTable->render('additional-field-in-checkout::admin.index', compact('fields'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        $type = AdditionalField::$type;
        return view('additional-field-in-checkout::admin.create', compact('type'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create AdditionalField')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            if ($request->input('options')) {
                $options = json_encode($request->input('options'));
            } else {
                $options = null;
            }
            $store = Store::find(getCurrentStore());
            $fields = new AdditionalField();
            $fields->name = $request->name;
            $fields->field_title = $request->field_title;
            $fields->type = $request->type;
            $fields->label = $request->label;
            $fields->class = $request->class;
            $fields->placeholder = $request->placeholder;
            $fields->default_value = $request->default_value;
            $fields->options = $options;
            $fields->store_id = $store->id;
            
            $fields->created_by = \Auth::user()->id;
            $fields->save();
            return redirect()->back()->with('success', __('Custom Field successfully Created.'));
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
        return view('additional-field-in-checkout::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $field = AdditionalField::find($id);
        if ($field) {
            $type = AdditionalField::$type;
            return view('additional-field-in-checkout::admin.edit', compact('field', 'type'));
        } else {
            return redirect()->back()->with('error', __('The Custom Field is not found.'));
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
        if (auth()->user() && auth()->user()->isAbleTo('Edit AdditionalField')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            if ($request->input('options')) {
                $options = json_encode($request->input('options'));
            } else {
                $options = null;
            }

            $fields = AdditionalField::find($id);
            if ($fields) {
                $fields->name = $request->name;
                $fields->field_title = $request->field_title;
                $fields->type = $request->type;
                $fields->label = $request->label;
                $fields->class = $request->class;
                $fields->placeholder = $request->placeholder;
                $fields->default_value = $request->default_value;
                $fields->options = $options;
                $fields->store_id = getCurrentStore();
                $fields->save();

                return redirect()->back()->with('success', __('Custom Field successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('The Custom Field is not found.'));
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
        if (auth()->user() && auth()->user()->isAbleTo('Delete AdditionalField')) {
            $fields = AdditionalField::find($id);
            if ($fields) {
                $fields->delete();
                return redirect()->back()->with('success', __('Custom Field delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The Custom Field is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
