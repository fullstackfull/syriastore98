<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Blog;
use App\Models\Store;
use App\Models\BlogCategory;
use App\Models\Utility;
use App\DataTables\BlogDataTable;

class BlogController extends Controller
{
    public function index(BlogDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Blog'))
        {
            return $dataTable->render('blog.index');
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
        $blogCategoryList = BlogCategory::where('status', 1)->where('store_id',getCurrentStore())->pluck('name', 'id');
        return view('blog.create', compact('blogCategoryList'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Blog'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'title' => 'required',
                    'short_description' => 'required',
                    'content' => 'required',
                    'category_id' => 'required',
                    'cover_image'=>'nullable',

                ],[
                    'category_id.required' => __('Please select category')
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $dir        = 'uploads/' . getCurrentStore();
            if($request->cover_image) {
                $image_size = $request->file('cover_image')->getSize();
                $result = Utility::updateStorageLimit(auth()->user()->creatorId(), $image_size);

                if ($result == 1)
                {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->cover_image->getClientOriginalName();
                    $path = Utility::upload_file($request,'cover_image',$fileName,$dir,[]);

                    if ($path['flag'] == 1) {
                        $url = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }


            }

            $blog                        = new Blog();
            $blog->title                 = $request->title;
            $blog->slug                  = generateUniqueSlug($request->slug ?? $request->title, new Blog);
            $blog->short_description     = $request->short_description;
            $blog->content               = $request->content;
            $blog->category_id           = $request->category_id;
            $blog->cover_image_url       = $path['full_url'] ?? '';
            $blog->cover_image_path      = $path['url'] ?? '';
            $blog->store_id              = getCurrentStore();
            $blog->save();
            return redirect()->back()->with('success', __('Blog successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $blog = Blog::find($id);
        $blogCategoryList = BlogCategory::where('status', 1)->where('store_id',getCurrentStore())->pluck('name', 'id');
        return view('blog.edit', compact('blogCategoryList' ,'blog'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Edit Blog'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'title' => 'required',
                    'short_description' => 'required',
                    'content' => 'required',
                    'category_id' => 'required',
                ],[
                    'category_id.required' => __('Please select category')
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $blog = Blog::find($id);
            $dir        = 'uploads/'. getCurrentStore();

            if($request->cover_image) {
                $image_size = $request->file('cover_image')->getSize();
                $file_path = $blog->cover_image_path;
                $result = Utility::updateStorageLimit(auth()->user()->creatorId(), $image_size);
                if ($result == 1)
                {
                    if (!empty($file_path) && \File::exists(base_path($file_path))) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                    }
                    $fileName = rand(10,100).'_'.time() . "_" . $request->cover_image->getClientOriginalName();
                    $path = Utility::upload_file($request,'cover_image',$fileName,$dir,[]);

                    if ($path['flag'] == 1) {
                        $blog->cover_image_url       = $path['full_url'];
                        $blog->cover_image_path      = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }

            }
            $slug = $request->slug ?? $request->name;
            if ($slug != $blog->slug) {
                $slug = generateUniqueSlug($request->slug ?? $request->name, new Blog);
            }

            $blog->title                 = $request->title;
            $blog->slug                  = $slug;
            $blog->short_description     = $request->short_description;
            $blog->content               = $request->content;
            $blog->category_id           = $request->category_id;
            $blog->save();

            return redirect()->back()->with('success', __('Blog successfully updated.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Delete Blog'))
        {
            $blog = Blog::find($id);
            $file_path =  $blog->cover_image_path;
            Utility::changeStorageLimit(auth()->user()->creatorId(), $file_path);

            $blog->delete();
            return redirect()->back()->with('success', __('Blog delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    public function blog_filter(Request $request, $storeSlug)
    {
        $store_id = getCurrenctStoreId($storeSlug);
        $slug = $storeSlug;
        $store = Store::where('id',$store_id)->first();
        if (!$store) {
            $return['html'] = null;
            $return['message'] = __('Store not found.');
            return response()->json($return);
        }
        
        $val = $request->value;

        if($val=='lastest'){
            $blogs = Blog::orderBy('created_at', 'asc')->where('store_id',$store_id)->get();

        }else{
            $blogs = Blog::orderBy('created_at', 'Desc')->where('store_id',$store_id)->get();
        }

        $html = '';
        $html = view('front_end.pages.blog', compact('slug','blogs','request'))->render();

        $return['html'] = $html;
        return response()->json($return);
    }
}
