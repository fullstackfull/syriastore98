<?php

namespace Workdo\ProductBadgeManagement\app\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Utility;
use Workdo\ProductBadgeManagement\app\Models\BadgeSetting;
use Workdo\ProductBadgeManagement\app\Models\ImageBadge as ModelsImageBadge;
use Illuminate\Support\Facades\Log;

class ImageBadgeController extends Controller
{
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        session()->put(['setting_tab' => 'badge_setting']);
        try {
            $validation = $request->validate([
                'bgimage' => 'image|mimes:jpeg,png,jpg,gif|max:2048|nullable',
                'img_position_top' => 'nullable|integer',
                'img_position_left' => 'nullable|integer',
                'img_padding_top' => 'nullable|integer',
                'img_padding_bottom' => 'nullable|integer',
                'img_padding_left' => 'nullable|integer',
                'img_padding_right' => 'nullable|integer',
                'img_radius_top_left' => 'nullable|integer',
                'img_radius_top_right' => 'nullable|integer',
                'img_radius_bottom_left' => 'nullable|integer',
                'img_radius_bottom_right' => 'nullable|integer',
                'img_opacity' => 'nullable|numeric',
                'img_width'=>'nullable|numeric',
            ]);

            // Handle imagebadge_enabled
            $value = $request->has('imagebadge_enabled') ? 'imagebadge' : null;

            // Update the BadgeSetting
            $set = BadgeSetting::where('store_id', getCurrentStore())->first();
            if ($set !== null) {
                $set->storage_setting = $value;
                $set->save();
            } else {
                BadgeSetting::create([
                    'store_id' => getCurrentStore(),
                    'storage_setting' => $value,
                ]);
            }

            $fileName = null;
            $filePath = null;

            // Handle the image upload
            if ($request->hasFile('bgimage')) {
                $image = $request->file('bgimage');
                $dir = 'uploads/' . getCurrentStore();
                $image_size = $image->getSize();
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);

                if ($result == 1) {
                    $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                    $path = Utility::upload_file($request, 'bgimage', $fileName, $dir, []);
                    if ($path['flag'] == '0') {
                        return redirect()->back()->with('error', $path['msg']);
                    }
                    $filePath = $path['url'] ?? null;
                } else {
                    return redirect()->back()->with('error', $result);
                }
            }

            // Get the store ID
            $store_id = getCurrentStore();

            // Fetch the existing image data
            $existingImageBadge = ModelsImageBadge::where('store_id', $store_id)->first();

            // If an image exists, update its attributes
            if ($existingImageBadge) {
                if ($filePath) {
                    $file_path = $existingImageBadge->filepath;
                    if (!empty($file_path) && \File::exists(base_path($file_path))) {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                    }
                    $existingImageBadge->filename = $fileName;
                    $existingImageBadge->filepath = $filePath;
                }
                $existingImageBadge->position_top = $request->img_position_top;
                $existingImageBadge->position_left = $request->img_position_left;
                $existingImageBadge->padding_top = $request->img_padding_top;
                $existingImageBadge->padding_bottom = $request->img_padding_bottom;
                $existingImageBadge->padding_left = $request->img_padding_left;
                $existingImageBadge->padding_right = $request->img_padding_right;
                $existingImageBadge->radius_top_left = $request->img_radius_top_left;
                $existingImageBadge->radius_top_right = $request->img_radius_top_right;
                $existingImageBadge->radius_bottom_left = $request->img_radius_bottom_left;
                $existingImageBadge->radius_bottom_right = $request->img_radius_bottom_right;
                $existingImageBadge->opacity = $request->img_opacity;
                $existingImageBadge->width = $request->img_width;
                $existingImageBadge->save();
            } else {
                // If an image doesn't exist, create a new entry
                $imageBadge = new ModelsImageBadge();
                $imageBadge->filename = $fileName;
                $imageBadge->filepath = $filePath;
                $imageBadge->store_id = $store_id;
                $imageBadge->position_top = $request->img_position_top;
                $imageBadge->position_left = $request->img_position_left;
                $imageBadge->padding_top = $request->img_padding_top;
                $imageBadge->padding_bottom = $request->img_padding_bottom;
                $imageBadge->padding_left = $request->img_padding_left;
                $imageBadge->padding_right = $request->img_padding_right;
                $imageBadge->radius_top_left = $request->img_radius_top_left;
                $imageBadge->radius_top_right = $request->img_radius_top_right;
                $imageBadge->radius_bottom_left = $request->img_radius_bottom_left;
                $imageBadge->radius_bottom_right = $request->img_radius_bottom_right;
                $imageBadge->opacity = $request->img_opacity;
                $imageBadge->width = $request->img_width;
                $imageBadge->save();
            }

            return redirect()->route('setting.index')->with('success', __('Image badge settings saved successfully.'));

        } catch (\Exception $e) {
            // Redirect back with an error message
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}



