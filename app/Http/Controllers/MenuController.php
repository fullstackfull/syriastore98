<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Category;
use App\Models\Page;
use App\Models\CustomLink;
use App\Models\Blog;
use App\Models\ProductBrand;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Session;
use App\DataTables\MenuDataTable;

class MenuController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(MenuDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Menu')) {
            return $dataTable->render('menu.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('menu.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Menu')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $menus            = new Menu();
            $menus->name      = $request->name;
            $menus->store_id  = getCurrentStore();
            $menus->save();
            return redirect()->back()->with('success', __('Menus successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Menu $menu)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Menu $menu)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Menu')) {
            $data['menu'] = $menu;
            $data['custom_link'] = CustomLink::where('menu_id', $menu->id)->get();
            $data['blogs'] = Blog::where('store_id', getCurrentStore())->get();
            $data['pages'] = Page::where('store_id', getCurrentStore())->where('page_status', 1)->pluck('page_name', 'id');
            $data['categories'] = Category::where('store_id', getCurrentStore())->where('status', 1)->pluck('name', 'id');
            $data['brands'] = ProductBrand::where('store_id', getCurrentStore())->where('status', 1)->pluck('name', 'id');
            $menuItems = $menu->menuItems()->whereNull('parent_id')->with('children')->orderBy('order', 'asc')->get();

            return view('menu.edit', $data, compact('menuItems'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Menu $menu)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Delete Menu'))
        {
            Menu::findOrFail($id)->delete();
            return redirect()->route('menus.index')->with('success', __('Menu deleted successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function updateOrder(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Menu')) {
            $menuId = $request->input('menuId');
            $menu = Menu::find($menuId);
            if (! $menu) {
                return response()->json(['status' => false, 'message' => __('Menu not found.')]);
            }
            $menu->update([
                'name' => $request->input('menuName'),
            ]);
            $menuData = $request->input('menu_structure');

            if (! $menuData) {
                return response()->json(['status' => false, 'message' => __('Invalid menu structure.')]);
            }

            foreach ($menuData as $menuItem) {
                // Update parent level items
                MenuItem::where('id', $menuItem['id'])->update([
                    'parent_id' => null,
                    'order' => $menuItem['order'],
                    'target' => $menuItem['target'],
                    'icon_type' => $menuItem['icon_type'],
                    'icon' => $menuItem['icon'],
                ]);

                // Update children recursively with correct parent_id
                if (! empty($menuItem['children'])) {
                    $this->updateMenuHierarchy($menuItem['children'], $menuItem['id']); // Pass correct parent ID
                }
            }

            return response()->json(['status' => true, 'message' => __('Menu order updated successfully.')]);
        } else {
            return response()->json(['status' => false, 'message' => __('Permission denied.')]);
        }
    }

    private function updateMenuHierarchy($children, $parentId)
    {
        foreach ($children as $child) {
            MenuItem::where('id', $child['id'])->update([
                'parent_id' => $parentId, // Correct parent-child relationship
                'order' => $child['order'],
                'target' => $child['target'],
                'icon_type' => $child['icon_type'],
                'icon' => $child['icon'],
            ]);

            // Recursively update children if exist
            if (! empty($child['children'])) {
                $this->updateMenuHierarchy($child['children'], $child['id']); // Pass correct parent ID
            }
        }
    }

    public function addItem(Request $request)
    {
        $menuItem = MenuItem::create([
            'name' => $request->input('name'),
            'url' => $request->input('url', null),
            'type' => $request->input('type'),
            'parent_id' => $request->input('parent_id', null),
        ]);

        return response()->json(['status' => true, 'message' => __('Item added successfully'), 'menuItem' => $menuItem]);
    }

    public function deleteItem($id)
    {
        $menuItem = MenuItem::find($id);

        if ($menuItem) {
            if ($menuItem->menu_itemable_type == "App\Models\CustomLink") {
                $customLink = CustomLink::find($menuItem->menu_itemable_id);
                $customLink->delete();
            }
            // Delete the menu item
            $response = $menuItem->delete();
            if ($response) {
                return response()->json(['status' => true, 'message' => __('Menu item deleted successfully')]);
            } else {
                return response()->json(['status' => false, 'message' => __('Something went wrong')]);
            }
        }

        return response()->json(['status' => false, 'message' => __('Menu item not found')]);
    }
}
