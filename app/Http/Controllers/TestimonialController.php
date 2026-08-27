<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use App\Models\Category;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Http\Request;
use App\DataTables\TestimonialDataTable;

class TestimonialController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index(TestimonialDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Testimonial'))
        {
            return  $dataTable->render('testimonial.index');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $categories = Category::where('store_id',getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        return view('testimonial.create', compact( 'categoryTree'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Testimonial'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    //'category_id' => 'required',
                    //'product_id' => 'required',
                    'rating_no' => 'required',
                    'title' => 'required',
                    'description' => 'required',
                    'status' => 'in:0,1',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $Testimonial = new Testimonial();

            $dir        = 'uploads/' . getCurrentStore() . '/testimonial';
            $totalImageSize = 0;
            if ($request->hasFile('avatar')) {
                $totalImageSize += $request->file('avatar')->getSize();
            }
            
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if ($request->avatar) {
                if ($result == 1) {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->avatar->getClientOriginalName();
                    $path = Utility::upload_file($request, 'avatar', $fileName, $dir, []);
                    if ($path['flag'] == 1) {
                        $Testimonial->avatar = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }

            $Testimonial->category_id = $request->category_id;
            $Testimonial->product_id = $request->product_id;
            $Testimonial->rating_no = $request->rating_no;
            $Testimonial->title = $request->title;
            $Testimonial->description = $request->description;
            $Testimonial->status = $request->status;
            $Testimonial->username = $request->username ?? null;
            $Testimonial->store_id = getCurrentStore();
            $Testimonial->save();

            // Testimonial::AvregeRating($request->product_id);

            return redirect()->back()->with('success', __('Testimonial create successfully.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function show(Testimonial $Testimonial)
    {
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function edit(Testimonial $Testimonial)
    {
        $categories = Category::where('store_id', getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $product = Product::where('category_id',$Testimonial->category_id)->where('store_id',getCurrentStore())->pluck('name', 'id')->prepend('Select Product', '');
        return view('testimonial.edit', compact( 'categoryTree', 'Testimonial', 'product'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Testimonial $Testimonial)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Testimonial'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    //'category_id' => 'required',
                    //'product_id' => 'required',
                    'rating_no' => 'required',
                    'title' => 'required',
                    'description' => 'required',
                    'status' => 'in:0,1',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $dir        = 'uploads/' . getCurrentStore() . '/testimonial';
            $totalImageSize = 0;
            if ($request->hasFile('avatar')) {
                $totalImageSize += $request->file('avatar')->getSize();
            }
            
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if ($request->avatar) {
                if ($result == 1) {
                    if (!empty($Testimonial->avatar) && $Testimonial->avatar != '/storage/uploads/default.jpg' && \File::exists(base_path($Testimonial->avatar))) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $Testimonial->avatar);
                    }
                    $fileName = rand(10,100).'_'.time() . "_" . $request->avatar->getClientOriginalName();
                    $path = Utility::upload_file($request, 'avatar', $fileName, $dir, []);
                    if ($path['flag'] == 1) {
                        $Testimonial->avatar = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }

            $Testimonial->category_id = $request->category_id;
            $Testimonial->product_id = $request->product_id;
            $Testimonial->rating_no = $request->rating_no;
            $Testimonial->title = $request->title;
            $Testimonial->description = $request->description;
            $Testimonial->status = $request->status;
            $Testimonial->username = $request->username;
            $Testimonial->save();

            // Testimonial::AvregeRating($request->product_id);

            return redirect()->back()->with('success', __('Testimonial update successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Testimonial  $Testimonial
     * @return \Illuminate\Http\Response
     */
    public function destroy(Testimonial $Testimonial)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Delete Testimonial'))
        {
            if (!empty($Testimonial->avatar) && $Testimonial->avatar != '/storage/uploads/default.jpg' && \File::exists(base_path($Testimonial->avatar))) {
                Utility::changeStorageLimit(\Auth::user()->creatorId(), $Testimonial->avatar);
            }
            $Testimonial->delete();
            return redirect()->back()->with('success', __('Testimonial delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function get_product(Request $request)
    {
        $id = $request->id;
        $value = $request->val;
        $Product = Product::where('category_id', $id)->get();
        $option = '<option value="">' . __('Select Product') . '</option>';
        foreach ($Product as $key => $Category) {
            $select = $value == $Category->id ? 'selected' : '';
            $option .= '<option value="' . $Category->id . '" '.$select.'>' . $Category->name . '</option>';
        }

        $select =  '<select class="form-control" data-role="tagsinput" id="product_id" name="product_id">'.$option.'</select>';
        $return['status'] = true;
        $return['html'] = $select;
        return response()->json($return);
    }

    public function terms(Request $request)
    {
        return view('other_page.terms');
    }

    public function return_policy(Request $request)
    {
        return view('other_page.privacy');
    }

    public function contact_us(Request $request)
    {
        return view('other_page.contact_us');
    }
}
