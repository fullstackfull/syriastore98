<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\ProductBrand;
use App\Models\Category;
use App\Models\CustomLink;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Http\Request;

class MenuItemsController extends Controller
{
    public function addCategoryMenu(Request $request, $menu_id)
    {
        $menu = Menu::find($menu_id);
        if (! $menu) {
            return response()->json(['status' => true, 'message' => __('Menu Not Found')]);
        }

        $menuItems = [];
        $categories = Category::whereIn('id', $request->category_ids)->get()->map(function ($category) use ($menu_id, &$menuItems) {
            $menuItem = $category->menuItems()->updateOrCreate([
                'menu_itemable_type' => Category::class,
                'menu_itemable_id' => $category->id,
                'menu_id' => $menu_id,
            ]);

            // Add the menu item to the response
            $menuItems[] = [
                'id' => $menuItem->id,
                'name' => $category->name,
                'menu_itemable_type' => Category::class,
                'target' => $menuItem->target ?? '_self',
            ];
        });

        return response()->json(['status' => true, 'message' => __('Category added successfully'), 'menuItems' => $menuItems]);
    }

    public function addPageMenu(Request $request, $menu_id)
    {
        $menu = Menu::find($menu_id);
        if (! $menu) {
            return response()->json(['status' => false, 'message' => __('Menu Not Found')]);
        }

        $menuItems = [];
        $pages = Page::whereIn('id', $request->pages_ids)->get()->map(function ($page) use ($menu_id, &$menuItems) {
            $menuItem = $page->menuItems()->updateOrCreate([
                'menu_itemable_type' => Page::class,
                'menu_itemable_id' => $page->id,
                'menu_id' => $menu_id,
            ]);

            // Add the menu item to the response
            $menuItems[] = [
                'id' => $menuItem->id,
                'name' => $page->name,
                'menu_itemable_type' => Page::class,
                'target' => $menuItem->target ?? '_self',
            ];
        });

        return response()->json(['status' => true, 'message' => __('Page added successfully'), 'menuItems' => $menuItems]);
    }

    public function addCustomLink(Request $request, $menu_id)
    {
        // Validate the incoming request
        $validated = $request->validate([
            'url' => 'required',
            'link_text' => 'required',
        ]);

        // Create the custom link
        $customLink = CustomLink::create([
            'menu_id' => $menu_id,
            'url' => $validated['url'],
            'title' => $validated['link_text'],
        ]);

        // Ensure a menu item is created with correct values
        $menuItem = new MenuItem;
        $menuItem->menu_id = $menu_id;
        $menuItem->menu_itemable_type = CustomLink::class;
        $menuItem->menu_itemable_id = $customLink->id;
        $menuItem->save();

        return response()->json(['status' => true, 'message' => __('Custom link added successfully'), 'menuItems' => [
            'id' => $menuItem->id,
            'title' => $customLink->title,
            'url' => $customLink->url,
            'menu_itemable_type' => CustomLink::class,
            'target' => $menuItem->target ?? '_self',
        ]]);
       
    }

    public function addBrandMenu(Request $request, $menu_id)
    {
        $menu = Menu::find($menu_id);
        if (! $menu) {
           return response()->json(['status' => false, 'message' => __('Menu Not Found')]);
        }

        $menuItems = [];
        $brands = ProductBrand::whereIn('id', $request->brand_ids)->get()->map(function ($brand) use ($menu_id, &$menuItems) {
            $menuItem = $brand->menuItems()->updateOrCreate([
                'menu_itemable_type' => ProductBrand::class,
                'menu_itemable_id' => $brand->id,
                'menu_id' => $menu_id,
            ]);

            // Add the menu item to the response
            $menuItems[] = [
                'id' => $menuItem->id,
                'name' => $brand->name,
                'menu_itemable_type' => ProductBrand::class,
                'target' => $menuItem->target ?? '_self',
            ];
        });

        return response()->json(['status' => true, 'message' => __('Brand added successfully'), 'menuItems' => $menuItems]);
    }

    public function addProductMenu(Request $request, $menu_id)
    {
        $menu = Menu::find($menu_id);
        if (! $menu) {
           return response()->json(['status' => false, 'message' => __('Menu Not Found')]);
        }

        $menuItems = [];
        $products = Product::whereIn('id', $request->product_ids)->where('is_draft', 0)->get()->map(function ($product) use ($menu_id, &$menuItems) {
            $menuItem = $product->menuItems()->create([
                'menu_itemable_type' => Product::class,
                'menu_itemable_id' => $product->id,
                'menu_id' => $menu_id,
            ]);

            // Add the menu item to the response
            $menuItems[] = [
                'id' => $menuItem->id,
                'name' => $product->name,
                'menu_itemable_type' => Product::class,
                'target' => $menuItem->target ?? '_self',
            ];
        });

        return response()->json(['status' => true, 'message' => __('Product added successfully'), 'menuItems' => $menuItems]);
    }

    public function addBlogMenu(Request $request, $menu_id)
    {
        $menu = Menu::find($menu_id);
        if (! $menu) {
            return response()->json(['status' => false, 'message' => __('Menu Not Found')]);
        }

        $menuItems = [];
        $blogs = Blog::whereIn('id', $request->blog_ids)->get()->map(function ($blog) use ($menu_id, &$menuItems) {
            $menuItem = $blog->menuItems()->updateOrCreate([
                'menu_itemable_type' => Blog::class,
                'menu_itemable_id' => $blog->id,
                'menu_id' => $menu_id,
            ]);

            // Add the menu item to the response
            $menuItems[] = [
                'id' => $menuItem->id,
                'name' => $blog->name,
                'menu_itemable_type' => Blog::class,
                'target' => $menuItem->target ?? '_self',
            ];
        });

        return response()->json(['status' => true, 'message' => __('Blog added successfully'), 'menuItems' => $menuItems]);
    }

    public function deleteCustomLink($id)
    {
        $customLink = CustomLink::find($id);

        if (! $customLink) {
            return redirect()->back()->with('failed', __('Custom Link Not Found!'));
        }

        $customLink->delete();

        return redirect()->back()->with('success', __('Custom Link Deleted Successfully!.'));

    }

    public function updateCustomLink(Request $request, $menu_id)
    {
        // Find the menu item
        $menu = MenuItem::findOrFail($menu_id); // Ensure MenuItem exists

        // Validate the request
        $validated = $request->validate([
            'url' => 'required|url',            // Ensure URL is valid
            'link_text' => 'required|string|max:255', // Ensure link text is a string
        ]);

        // Get the related CustomLink
        $customLink = $menu->menuItemable; // Get related model (CustomLink)

        // Ensure it is an instance of CustomLink
        if (! $customLink || ! $customLink instanceof CustomLink) {
            return response()->json(['status' => false, 'message' => __('Custom link not found')]);
        }

        // Update the custom link data
        $customLink->url = $validated['url'];
        $customLink->title = $validated['link_text'];
        $customLink->save();

        return response()->json(['status' => true, 'message' => __('Custom link updated successfully')]);
    }
}
