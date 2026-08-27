<?php

namespace Workdo\ProductBadgeManagement\app\Http\Controllers;

use Workdo\ProductBadgeManagement\app\Models\BadgeSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Workdo\ProductBadgeManagement\app\Models\ImageBadge;

class BadgeController extends Controller
{

    /**
     * Show the form for editing the badge settings.
     *
     * @return \Illuminate\View\View
     */
    public function edit()
    {
        // Retrieve the badge settings from the database
        $settings = BadgeSetting::firstOrCreate(['store_id' => getCurrentStore()]);

        // Pass the settings to a view for editing
        return view('product-badge-management.edit', ['settings' => $settings]);
    }

    /**
     * Update the badge settings in the database.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
    {
        session()->put(['setting_tab' => 'badge_setting']);
        try {
            // Validate the incoming request
            $validatedData = $request->validate([
                'textcolor' => ['required', 'string'],
                'bgcolor' => ['required', 'string'],
                'badgetxt' => ['required', 'string'],
                'position_top' => ['required', 'integer'],
                'position_left' => ['required', 'integer'],
                'padding_top' => ['required', 'integer'],
                'padding_bottom' => ['required', 'integer'],
                'padding_left' => ['required', 'integer'],
                'padding_right' => ['required', 'integer'],
                'radius_top_left' => ['required', 'integer'],
                'radius_top_right' => ['required', 'integer'],
                'radius_bottom_left' => ['required', 'integer'],
                'radius_bottom_right' => ['required', 'integer'],
                'opacity' => ['required'],
                'width'=>'nullable|numeric',

            ]);

            $input = $request->all();
            if(isset($request->textbadge_enabled)) { 
                if ($request->textbadge_enabled == 'on') {
                    $input['storage_setting'] = "textbadge";
                }
                
            } 
          
            // Update or create badge settings
            $settings = BadgeSetting::updateOrCreate(
                ['store_id' => getCurrentStore()],
              ['store_id' => getCurrentStore()]+$input
            );

            // Redirect back with success message
            return redirect()->back()->with('success', 'Badge settings updated successfully.');
        } catch (\Exception $e) {
            // Redirect back with error message if an exception occurs
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function settingForm(Request $request) {
		try {
		    $existingImageBadge = ImageBadge::where('store_id', getCurrentStore())->first();
            $setting = BadgeSetting::where('store_id', getCurrentStore())->first();
                
		    $html = view('product-badge-management::pages.tab_form', compact('setting','existingImageBadge'))->render();
		    
		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Badge setting form get successfully."),
		        "data" => ['content' => $html]
		    ]);
		} catch (\Exception $e) {
		    // Return JSON response
		    return response()->json([
		        'is_success' => false,
		        "msg" => $e->getMessage(),
		        "data" => ['content' => null]
		    ]);
		}
	} 
}
