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
                return redirect()->back()->with('error', implode(' ', $messages->all()));
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

    /**
     * P2.5-PKG-B. Does the caller own this blog post?
     *
     * `Blog` is absent from `config/tenancy.php`'s `scoped_models`, so
     * `StoreScope::isActiveFor()` short-circuits and the global scope adds NO predicate for any
     * principal -- `Blog::find($id)` reaches every tenant's rows.
     *
     * MEASURED in `ContentFamilyCrossTenantTest` before this guard existed: all seven controllers
     * of this shape failed their cross-tenant cases while all seven positive controls passed, so
     * the fixtures were sound and the holes were real.
     *
     * Fails closed when either side is absent -- a row with a NULL `store_id` matches no store
     * predicate anywhere in the application and must not become visible here. No operator limb,
     * as on `ProductBrandController::callerOwnsBrand()`.
     */
    private function callerOwnsBlog(?Blog $blog): bool
    {
        $user = auth()->user();

        if ($user === null || $blog === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($blog->store_id)
            && (int) $blog->store_id === (int) $currentStore;
    }

    public function edit($id)
    {
        $blog = Blog::find($id);

        // P2.5-PKG-B. edit() had NO permission check at all, so any authenticated principal -- staff holding
        // zero permissions -- could read any tenant's full post by id. 404 for
        // foreign-or-absent, so the response is not an existence oracle.
        if (! $this->callerOwnsBlog($blog)) {
            abort(404);
        }
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
            $blog = Blog::find($id);

            // P2.5-PKG-B. The permission was checked and the owner was not, so this rewrote
            // ANOTHER STORE'S post. ABOVE THE VALIDATOR deliberately: with the guard below it, an
            // INVALID payload returned the validation redirect and a VALID one returned 404, which
            // distinguishes "exists elsewhere" from "refused" for a caller who can vary the
            // payload. Raised by the tenant-authz review, which measured that my first placement
            // did not hold the invariant this comment claims.
            if (! $this->callerOwnsBlog($blog)) {
                abort(404);
            }

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
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

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
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsBlog($blog)) {
                abort(404);
            }

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
