<?php

namespace Workdo\SidebarCustomization\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\SidebarCustomization\app\Models\SideMenuOption;
use Illuminate\Support\Facades\Auth;
use Workdo\SidebarCustomization\app\Models\SideMenuOrder;

class SideMenuController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        // if (auth()->user() && auth()->user()->isAbleTo('Manage Sidebar Menus')) {
            $user = auth()->user();
            if($user->type == 'super admin')
            {
                $sortedModules = SideMenuOption::where('created_by', $user->id)->where('store_id', $user->current_store)->where('parent_id', null)->orderBy('order')->get();
            }else{
                $modules = SideMenuOption::where( 'type', 'admin')->where('created_by', 0)->where('store_id', null)->where('parent_id', null)->orderBy('order')->get();

                $MenuItems = SideMenuOption::where('type', 'admin')->where('created_by', $user->id)->where('store_id', $user->current_store)->where('parent_id', null)->orderBy('order')->get();

                if (!$MenuItems->isEmpty()) {
                    $modules = $modules->merge($MenuItems)->sortBy('order');
                }

                $customOrder = SideMenuOrder::whereIn('menu_id', $modules->pluck('id'))->get();

                $orderMap = $customOrder->pluck('order', 'menu_id')->toArray();

                $sortedModules = $modules->sortBy(function ($module) use ($orderMap) {
                    return isset($orderMap[$module->id]) ? $orderMap[$module->id] : $module->order;
                });
            }
            return view('sidebar-customization::super-admin.index',compact('sortedModules'));
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        $user = auth()->user();
        $menus = SideMenuOption::$menus;
        $links = SideMenuOption::$links;
        $hrefs = SideMenuOption::$hrefs;
        $show_window = SideMenuOption::$show_window;
        if($user->type == 'super admin')
        {
            $modules = SideMenuOption::where('created_by', $user->id)->where('store_id', $user->current_store)->where('parent_id',null)->get();
        }else{
            $modules = SideMenuOption::where( 'type', 'admin')->where('created_by', 0)->where('store_id', null)->where('parent_id', null)->orderBy('order')->get();

            $MenuItems = SideMenuOption::where('type', 'admin')->where('created_by', $user->id)->where('store_id', $user->current_store)->orderBy('order')->get();

            if (!$MenuItems->isEmpty()) {
                $modules = $modules->merge($MenuItems)->sortBy('order');
            }
        }
        
        return view('sidebar-customization::super-admin.create',compact('menus', 'links', 'hrefs', 'show_window', 'modules'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        // if (auth()->user() && auth()->user()->isAbleTo('Create Sidebar Menus')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'menu_type' => 'required|in:mainmenu,submenu',
                    'name' => 'required|max:255',
                    'link_type' => 'required|in:internal,external,hash',
                    'links' => 'required_if:link_type,internal',
                    'link' => 'required_if:link_type,external',
                    'window' => 'required',
                    'modules' => 'required_if:menu_type,submenu'
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->withInput()->with('error', $messages->first());
            }
            $menu_builder = new SideMenuOption();
            if ($request->menu_type == 'mainmenu') {
                $max_position = SideMenuOption::where('menu_type', 'mainmenu')->where('type',auth()->user()->type)->max('position');
                $menu_builder->menu_type = $request->menu_type;
                $menu_builder->name = $request->name;
                $menu_builder->title = $request->name;
                $menu_builder->type = auth()->user()->type;
                $menu_builder->position = $max_position ? $max_position + 1 : 1;
                $menu_builder->order = $max_position ? $max_position + 1 : 1;
                $menu_builder->link_type = $request->link_type;
                $menu_builder->parent_id = null;
                if ($request->link_type == 'internal') {
                    $menu_builder->link = $request->links;
                } elseif ($request->link_type == 'external') {
                    $menu_builder->link = json_encode($request->link);
                } else {
                    $menu_builder->link = '#';
                }
                $menu_builder->window_type = $request->window;
                $menu_builder->icon = $request->icon;
                $menu_builder->store_id = getCurrentStore();
                $menu_builder->created_by = auth()->user()->id;
            } else {
                $menu_builder->menu_type = $request->menu_type;
                $menu_builder->name = $request->name;
                $menu_builder->title = $request->name;
                $menu_builder->type = auth()->user()->type;
                $menu_builder->order = $request->position;
                $menu_builder->position = $request->position;
                $menu_builder->link_type = $request->link_type;
                $menu_builder->parent_id = $request->modules;
                if ($request->link_type == 'internal') {
                    $menu_builder->link = $request->links;
                } elseif ($request->link_type == 'external') {
                    $menu_builder->link = json_encode($request->link);
                } else {
                    $menu_builder->link = '#';
                }
                $menu_builder->window_type = $request->window;
                $menu_builder->icon = '';
                $menu_builder->store_id = getCurrentStore();
                $menu_builder->created_by = auth()->user()->id;
            }

            if ($request->menu_type == 'submenu') {
                $convert_link = SideMenuOption::where('name', $request->modules)->first(); 
                    $convert_link->link_type = 'hash';
                $convert_link->window_type = 'same_window';
                $convert_link->link = '#';
                $convert_link->save();
            }


            if(!empty($request->is_enable) && $request->is_enable == 'on')
            {
                $menu_builder->is_enable =$request->is_enable;
            }
            $menu_builder->save();

            return redirect()->route('sidemenu.index')->with('success', __('Menu has been created successfully.'));
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('sidebar-customization::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $menu_builder = SideMenuOption::find($id);
        $user = auth()->user();
        $menus = SideMenuOption::$menus;
        $links = SideMenuOption::$links;
        $hrefs = SideMenuOption::$hrefs;
        $show_window = SideMenuOption::$show_window;
        $modules = SideMenuOption::where('created_by', $user->id)->where('parent_id',null)->where('store_id', $user->current_store)->get();
        
        return view('sidebar-customization::super-admin.edit', compact('menu_builder', 'menus', 'links', 'hrefs', 'show_window', 'modules'));
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        // if (auth()->user() && auth()->user()->isAbleTo('Edit Sidebar Menus')) {
            $sidemenubuilder = SideMenuOption::find($id);
            if (Auth::user()->type == 'admin') {
                if ($request->menu_type == 'mainmenu') {
                    $sidemenubuilder->menu_type = $request->menu_type;
                    $sidemenubuilder->name = $request->title;
                    $sidemenubuilder->title = $request->title;
                    $sidemenubuilder->type = auth()->user()->type;
                    $sidemenubuilder->position = $request->position ?? 0;
                    $sidemenubuilder->order = $request->position ?? 0;
                    $sidemenubuilder->link_type = $request->link_type;
                    $sidemenubuilder->parent_id = null;
                    if ($request->link_type == 'internal') {
                        $sidemenubuilder->link = $request->links;
                    } elseif ($request->link_type == 'external') {
                        $sidemenubuilder->link = json_encode($request->link);
                    } else {
                        $sidemenubuilder->link = '#';
                    }
                    $sidemenubuilder->window_type = $request->window;
                    $sidemenubuilder->icon = $request->icon;
                    $sidemenubuilder->store_id = getCurrentStore();
                    $sidemenubuilder->created_by = auth()->user()->id;
                } else {
                    $sidemenubuilder->menu_type = $request->menu_type;
                    $sidemenubuilder->name = $request->title;
                    $sidemenubuilder->title = $request->title;
                    $sidemenubuilder->position = $request->position;
                    $sidemenubuilder->order = $request->position;
                    $sidemenubuilder->type = auth()->user()->type; 
                    $sidemenubuilder->link_type = $request->link_type;
                    $sidemenubuilder->parent_id = $request->modules;
                    if ($request->link_type == 'internal') {
                        $sidemenubuilder->link = $request->links;
                    } elseif ($request->link_type == 'external') {
                        $sidemenubuilder->link = json_encode($request->link);
                    } else {
                        $sidemenubuilder->link = '#';
                    }
                    $sidemenubuilder->window_type = $request->window;
                    $sidemenubuilder->icon = '';
                    $sidemenubuilder->store_id = getCurrentStore();
                    $sidemenubuilder->created_by = auth()->user()->id;
                }

                if ($request->menu_type == 'submenu') {
                    $convert_link = SideMenuOption::where('id', $request->modules)->first();
                    $convert_link->link_type = 'hash';
                    $convert_link->window_type = 'same_window';
                    $convert_link->link = '#';
                    $convert_link->save();
                }
                $sidemenubuilder->is_enable = $request->is_enable == 'on' ? 'on' : 'off';
                $sidemenubuilder->save();
                return redirect()->route('sidemenu.index')->with('success', __('Menu details are updated successfully'));
            }
            elseif(Auth::user()->type == 'super admin'){
                if ($request->menu_type == 'mainmenu') {
                    $sidemenubuilder->menu_type = $request->menu_type;
                    $sidemenubuilder->name = $request->title;
                    $sidemenubuilder->title = $request->title;
                    $sidemenubuilder->type = auth()->user()->type;
                    $sidemenubuilder->position = $request->position ?? 0;
                    $sidemenubuilder->order = $request->position ?? 0;
                    $sidemenubuilder->link_type = $request->link_type;
                    $sidemenubuilder->parent_id = null;
                    if ($request->link_type == 'internal') {
                        $sidemenubuilder->link = $request->links;
                    } elseif ($request->link_type == 'external') {
                        $sidemenubuilder->link = json_encode($request->link);
                    } else {
                        $sidemenubuilder->link = '#';
                    }
                    $sidemenubuilder->window_type = $request->window;
                    $sidemenubuilder->icon = $request->icon;
                    $sidemenubuilder->store_id = getCurrentStore();
                    $sidemenubuilder->created_by = auth()->user()->id;
                } elseif($request->menu_type == 'submenu') {
                    $sidemenubuilder->menu_type = $request->menu_type;
                    $sidemenubuilder->name = $request->title;
                    $sidemenubuilder->title = $request->title;
                    $sidemenubuilder->type = auth()->user()->type; 
                    $sidemenubuilder->position = $request->position;
                    $sidemenubuilder->order = $request->position;
                    $sidemenubuilder->link_type = $request->link_type;
                    $sidemenubuilder->parent_id = $request->modules;
                    if ($request->link_type == 'internal') {
                        $sidemenubuilder->link = $request->links;
                    } elseif ($request->link_type == 'external') {
                        $sidemenubuilder->link = json_encode($request->link);
                    } else {
                        $sidemenubuilder->link = '#';
                    }
                    $sidemenubuilder->window_type = $request->window;
                    $sidemenubuilder->icon = '';
                    $sidemenubuilder->store_id = getCurrentStore();
                    $sidemenubuilder->created_by = auth()->user()->id;
                }else{
                    $sidemenubuilder->icon = $request->icon;
                }
                if ($request->menu_type == 'submenu') {
                    $convert_link = SideMenuOption::where('name', $request->modules)->first(); 

                    $convert_link->link_type = 'hash';
                    $convert_link->window_type = 'same_window';
                    $convert_link->link = '#';
                    $convert_link->save();
                }
                $sidemenubuilder->is_enable = $request->is_enable == 'on' ? 'on' : 'off';
                $sidemenubuilder->save();

                return redirect()->back()->with('success', __('The Menu builder details are updated successfully'));
            }
            else{
                return redirect()->back()->with('error', __('Permission Denied.'));
            }
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id, SideMenuOption $sidemenuoption)
    {
        // if (auth()->user() && auth()->user()->isAbleTo('Delete Sidebar Menus')) {
            $menu_builder = SideMenuOption::find($id);
            $sub_menu = SideMenuOption::where('parent_id', $menu_builder->name)->get();

            $menu_builder->delete();
            foreach ($sub_menu as $key => $value) {
                $value->delete();
            }
            return redirect()->back()->with('success', __('Side Menu delete successfully.'));
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }
    public function UpdateOrder(Request $request)
    {
        $order = $request->input('order');
        $user = auth()->user();
        foreach ($order as $index => $id) {
            $menuItem = SideMenuOption::find($id);
            if ($user->type == 'super admin') {
                if ($menuItem) {
                    $menuItem->order = $index;
                    $menuItem->save();
                }
            }else{
                if ($menuItem) {
                    $data = [
                        'menu_id'    => $menuItem->id,
                        'position'   => $index,
                        'order'      => $index,
                        'store_id'   => $user->current_store,
                        'created_by' => $user->id
                    ];
    
                    SideMenuOrder::updateOrCreate(
                        [
                            'menu_id'    => $menuItem->id,
                            'store_id'   => $user->current_store, 
                            'created_by' => $user->id,
                        ],
                        $data
                    );
                }
            }
        }
    }

    public function updateSidebar()
    {
        $user = auth()->user();
        if ($user->type == 'super admin') {
            $sortedModules = SideMenuOption::where('type', 'super admin')->orderBy('order')->get();
        } else {
            $sideMenuItems = SideMenuOption::where('type', 'admin')->where('created_by', 0)->where('store_id', null)->orderBy('order')->get();

            $MenuItems = SideMenuOption::where('type', 'admin')->where('created_by', $user->id)->where('store_id', $user->current_store)->orderBy('order')->get();

            if (!$MenuItems->isEmpty()) {
                $sideMenuItems = $sideMenuItems->merge($MenuItems)->sortBy('order');
            }

            $customOrder = SideMenuOrder::whereIn('menu_id', $sideMenuItems->pluck('id'))
                ->where('created_by', $user->id)
                ->where('store_id', $user->current_store)
                ->get();

            $orderMap = $customOrder->pluck('order', 'menu_id')->toArray();

            $sortedModules = $sideMenuItems->sortBy(function ($module) use ($orderMap) {
                return isset($orderMap[$module->id]) ? $orderMap[$module->id] : $module->order;
            });
        }
        $menu = new \App\Classes\Menu($user);
        
        foreach ($sortedModules as $item) {
            $menu->menu[] = [
                'id' => $item->id,
                'category' => $item->category,
                'title' => $item->title,
                'icon' => $item->icon,
                'name' => $item->name,
                'parent' => $item->parent_id,
                'link_type' => $item->link_type ?? null, 
                'order' => isset($orderMap[$item->id]) ? $orderMap[$item->id] : $item->order,
                'route' => $item->link,
                'module' => $item->module ?? null,
                'window_type' => $item->window_type ?? null, 
                'is_enable' => $item->is_enable ?? null, 
                'permission' => $item->permission ?? null,
            ];
        }
        return response()->json([
            'html' => generateSideMenu($menu->menu, null)
        ]);
    }

    public function updateChildMenu(Request $request,$id)
    {
        $sideMenu = SideMenuOption::find($id);

        $user = auth()->user();
        if ($user->type == 'super admin') {
            $allMenus = SideMenuOption::where('created_by', $user->id)->where('store_id', $user->current_store)->orderBy('order')->get();
        }else{
            $allMenus = SideMenuOption::where('type', 'admin')->orderBy('order')->get();
            $MenuItems = SideMenuOption::where('type', 'admin')->where('created_by', $user->id)->where('store_id', $user->current_store)->orderBy('order')->get();

            if (!$MenuItems->isEmpty()) {
                $allMenus = $allMenus->merge($MenuItems)->sortBy('order');
            }
        }
        $menus = $this->buildMenuHierarchy($allMenus, $sideMenu->name);
        return view('sidebar-customization::super-admin.menu',compact('sideMenu','menus'));
    }
    public static function buildMenuHierarchy($menus, $parentName)
    {
        $user = auth()->user();
        $menuHierarchy = [];
        $menusMap = $menus->keyBy('name');
        $customOrder = SideMenuOrder::where('created_by', $user->id)
        ->where('store_id', $user->current_store)
        ->get();

        $orderMap = $customOrder->pluck('order', 'menu_id')->toArray();

        $menus = $menus->sortBy(function ($menu) use ($orderMap) {
        return isset($orderMap[$menu->id]) ? $orderMap[$menu->id] : 0;
        });
        foreach ($menus as $menu) {
            if ($menu->parent_id == $parentName) {
                $menu->children = collect();
                foreach ($menus as $child) {
                    if ($child->parent_id == $menu->name) {
                        $menu->children->push($child);
                    }
                }
                $menuHierarchy[] = $menu;
            }
        }
        return $menuHierarchy;
    }
    
    public function updateMenuHierarchy(Request $request)
    {
        $items = $request->input('items');
        $user = auth()->user();
        if ($user->type == 'super admin') {
            foreach ($items as $item) {
                $menuItem = SideMenuOption::find($item['id']);
                if ($menuItem && $item['parent_id'] != 0) {
                    $sub_menu = SideMenuOption::find($item['parent_id']);
                    $menuItem->parent_id = $sub_menu['name'];
                    $menuItem->save(); 
                }elseif($item['parent_id'] == 0)
                {
                    $menu_menu = SideMenuOption::find($request['mainMenuValue']);
                    $menuItem->parent_id = $menu_menu->name;
                    $menuItem->save(); 
                }
            }
        }else{
            foreach ($items as $item) {
                $menuItem = SideMenuOption::find($item['id']);
                if ($menuItem && $item['parent_id'] != 0) {
                    $sub_menu = SideMenuOption::find($item['parent_id']);
                    $menuItem->parent_id = $sub_menu['name'];
                    $menuItem->save(); 
                }elseif($item['parent_id'] == 0)
                {
                    $data = [
                        'menu_id'    => $menuItem->id,
                        'position'   => $item['order'],
                        'order'      => $item['order'],
                        'store_id'   => $user->current_store,
                        'created_by' => $user->id
                    ];
    
                    SideMenuOrder::updateOrCreate(
                        [
                            'menu_id'    => $menuItem->id,
                            'store_id'   => $user->current_store, 
                            'created_by' => $user->id,
                        ],
                        $data
                    );

                    $menu_menu = SideMenuOption::find($request['mainMenuValue']);
                    // $menuItem->order = $item['order'];
                    $menuItem->parent_id = $menu_menu->name;
                    $menuItem->save(); 
                }
            }
        }
    }
    
    public function iFrameData($id)
    {
        // if (Auth::user()->isAbleTo('sidemenubuilder manage')) {
            $sideMenuBuilder = SideMenuOption::find($id);
            try {
                $iframe = SideMenuOption::where('id', $id)->where('window_type', 'iframe')->first();
            } catch (\Throwable $th) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }

            if ($iframe == null) {
                return redirect()->route('sidemenu.index');
            }
            return view('sidebar-customization::super-admin.iframe', compact('sideMenuBuilder', 'iframe'));

        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }
}
