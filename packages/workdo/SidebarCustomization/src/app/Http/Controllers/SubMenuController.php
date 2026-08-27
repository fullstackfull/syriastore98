<?php

namespace Workdo\SidebarCustomization\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\SidebarCustomization\app\Models\SideMenuOption;
use Workdo\SidebarCustomization\app\Http\Controllers\SideMenuController;
class SubMenuController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('sidebar-customization::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create($id)
    {
        $user = auth()->user();
        $links = SideMenuOption::$links;
        $hrefs = SideMenuOption::$hrefs;
        $show_window = SideMenuOption::$show_window;

        $sideMenu = SideMenuOption::find($id);

        $allMenus = SideMenuOption::where('created_by', $user->id)->where('store_id', $user->current_store)->get();
        $modules = SideMenuController::buildMenuHierarchy($allMenus, $sideMenu->name);

        return view('sidebar-customization::super-admin.sub-menu.create',compact( 'links', 'hrefs', 'show_window', 'modules','sideMenu'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required|max:255',
                'link_type' => 'required|in:internal,external,hash',
                'links' => 'required_if:link_type,internal',
                'link' => 'required_if:link_type,external',
                'window' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->withInput()->with('error', $messages->first());
        }
        $menu_builder = new SideMenuOption();
        $menu_builder->menu_type = 'submenu';
        $menu_builder->name = $request->name;
        $menu_builder->title = $request->name;
        $menu_builder->type = auth()->user()->type;
        $menu_builder->order = $request->position;
        $menu_builder->position = $request->position;
        $menu_builder->link_type = $request->link_type;
        $menu_builder->parent_id = $request->modules ?? $request->parent_menu;
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
        
        $convert_link = SideMenuOption::where('name', $menu_builder->parent_id)->first(); 
        $convert_link->link_type = 'hash';
        $convert_link->window_type = 'same_window';
        $convert_link->link = '#';
        $convert_link->save();

        if(!empty($request->is_enable) && $request->is_enable == 'on')
        {
            $menu_builder->is_enable =$request->is_enable;
        }
        $menu_builder->save();

        return redirect()->back()->with('success', __('Menu has been created successfully.'));
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
    public function edit($id,$parent_id)
    {
        $user = auth()->user();
        $links = SideMenuOption::$links;
        $hrefs = SideMenuOption::$hrefs;
        $show_window = SideMenuOption::$show_window;
        $menus = SideMenuOption::$menus;
        $menu_builder = SideMenuOption::find($id);
        $parent_data = SideMenuOption::find($parent_id);

        $allMenus = SideMenuOption::where('created_by', $user->id)->where('store_id', $user->current_store)->get();

        $modules = SideMenuOption::where('created_by', $user->id)->where('store_id', $user->current_store)->get();

        return view('sidebar-customization::super-admin.sub-menu.edit',compact( 'links', 'hrefs', 'show_window', 'modules','menu_builder','menus','parent_data'));
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {

        $sidemenubuilder = SideMenuOption::find($id);

        if ($request->menu_type == 'mainmenu') {
            $max_position = SideMenuOption::where('menu_type', 'mainmenu')->where('type',auth()->user()->type)->max('position');
            $sidemenubuilder->menu_type = $request->menu_type;
            $sidemenubuilder->position = $max_position ? $max_position + 1 : 1;
            $sidemenubuilder->order = $max_position ? $max_position + 1 : 1;
            $sidemenubuilder->parent_id = null;
            $sidemenubuilder->icon = $request->icon;
        } elseif($request->menu_type == 'submenu') {
            $sidemenubuilder->menu_type = $request->menu_type;
        }else{
            $sidemenubuilder->icon = $request->icon;
        }
        if ($request->menu_type == 'submenu') {
            $convert_link = SideMenuOption::where('name', $sidemenubuilder->parent_id)->first(); 
            $convert_link->link_type = 'hash';
            $convert_link->window_type = 'same_window';
            $convert_link->link = '#';
            $convert_link->save();
        }
        $sidemenubuilder->is_enable = $request->is_enable == 'on' ? 'on' : 'off';
        $sidemenubuilder->save();

        return redirect()->back()->with('success', __('Menu details are updated successfully'));
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
