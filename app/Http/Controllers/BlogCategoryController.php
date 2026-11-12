<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BlogCategory;
use App\DataTables\BlogCategoryDataTable;
use App\Models\Blog;
use App\Models\Utility;

class BlogCategoryController extends Controller
{
    public function index(BlogCategoryDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Blog Category'))
        {
            return $dataTable->render('blog-category.index');
        }else{
            return redirect()->back()->with('error',__('Permission Denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('blog-category.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Blog Category'))
        {
            $validator = \Validator::make(
                $request->all(), [
                                   'name' => 'required'
                                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }


            $blogCategory = new BlogCategory();
            $blogCategory->name         = $request->name;
            $blogCategory->status       = $request->status;
            $blogCategory->store_id     = getCurrentStore();

            $blogCategory->save();

            return redirect()->back()->with('success', __('Category successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show($id)
    {
        //
    }


    /**
     * P2.5-PKG-B. Does the caller own this blog category?
     *
     * `BlogCategory` is absent from `config/tenancy.php`'s `scoped_models`, so
     * `StoreScope::isActiveFor()` short-circuits and the global scope adds NO predicate for any
     * principal -- `BlogCategory::find($id)` reaches every tenant's rows.
     *
     * MEASURED in `ContentFamilyCrossTenantTest` before this guard existed: all seven controllers
     * of this shape failed their cross-tenant cases while all seven positive controls passed, so
     * the fixtures were sound and the holes were real.
     *
     * Fails closed when either side is absent -- a row with a NULL `store_id` matches no store
     * predicate anywhere in the application and must not become visible here. No operator limb,
     * as on `ProductBrandController::callerOwnsBrand()`.
     */
    private function callerOwnsBlogCategory(?BlogCategory $blogCategory): bool
    {
        $user = auth()->user();

        if ($user === null || $blogCategory === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($blogCategory->store_id)
            && (int) $blogCategory->store_id === (int) $currentStore;
    }

    public function edit($id)
    {
        $blogCategory = BlogCategory::find($id);


        // P2.5-PKG-B. This read was unguarded.
        if (! $this->callerOwnsBlogCategory($blogCategory)) {
            abort(404);
        }
        return view('blog-category.edit', compact('blogCategory'));
    }

    public function update(Request $request, $id)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Edit Blog Category'))
        {
            $blogCategory = BlogCategory::find($id);

            // P2.5-PKG-B. ABOVE THE VALIDATOR, for the reason recorded on BlogController::update():
            // below it, the response shape itself distinguishes a foreign row from a refusal.
            if (! $this->callerOwnsBlogCategory($blogCategory)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(), [
                                   'name' => 'required',
                               ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }


            $blogCategory->name = $request->name;


            $blogCategory->status       = $request->status;
            $blogCategory->save();

            return redirect()->back()->with('success', __('Category successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroy($id)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Delete Blog Category'))
        {
            $blogCategory = BlogCategory::find($id);
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsBlogCategory($blogCategory)) {
                abort(404);
            }

            $blogs = Blog::where('category_id',$blogCategory->id)->get();
            foreach ($blogs as $blog) {
                $file_path =  $blog->cover_image_path;
                Utility::changeStorageLimit(auth()->user()->creatorId(), $file_path);
            }
            $blogCategory->delete();
            return redirect()->back()->with('success', __('Category delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
