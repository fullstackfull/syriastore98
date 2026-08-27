<?php

namespace Workdo\ThemeCustomizer\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Workdo\ThemeCustomizer\app\Models\StoreThemeCustomizer;
use Illuminate\Support\Facades\Validator;
use Qirolab\Theme\Theme;
use App\Models\Category;
use App\Models\Testimonial;
use App\Models\ProductBrand;
use App\Models\Blog;
use App\Models\Product;

class ThemeCustomizerController extends Controller
{
    public function themeCustomizerSetting(Request $request)
    {
        // Validate incoming request data
        $validator = Validator::make(
            $request->all(),
            [
                'class_json'  => 'required'
            ]
        );

        // If validation fails, redirect back with the first error message
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->errors()->first());
        }

        // Prepare the data to be saved
        $data = [
            'is_publish' => $request->input('is_publish') ? $request->input('is_publish') : 'off',
            'class_json'         => json_encode($request->input('class_json')),
        ];

        $storeQuery = StoreThemeCustomizer::query();
        // Attempt to find the existing store theme customizer record
        $store = (clone $storeQuery)->where('store_id', getCurrentStore())
                    ->first();

        // Update the existing record or create a new one if it doesn't exist
        $status = $store
                    ? $store->update($data)
                    : (clone $storeQuery)->create([
                        'store_id' => getCurrentStore(),
                        'theme_id' => $request->theme_id,
                    ] + $data);

        // Return with success or error message based on operation status
        return $status
                ? redirect()->back()->with('success', __('Theme customize setting saved successfully.'))
                : redirect()->back()->with('error', __('Something went wrong'));
    }

    public function themeCustomizerPreview(Request $request)
    {
        $theme = request()->route('theme');
        $store = StoreThemeCustomizer::where('store_id', getCurrentStore())->first();
        if ($store) {
            if (!empty($store->class_json)) {
                $colors = (array) json_decode($store->class_json);
            } else {
                $colors = $this->getThemeColors($theme);
            }   
            
            $isPublish = $store->is_publish;
        } else {
            $colors = $this->getThemeColors($theme);
            $isPublish = null;
        }

        return view('theme-customizer::pages.preview', compact('theme', 'colors', 'isPublish'));
    }

    private function getThemeColors($theme)
    {
        // Path to your CSS file
        $cssFilePath = asset('themes/' . $theme .  '/assets/css/main-style.css');

        // Define the variables you want to extract
        $variables = ['--primary-color', '--primary-dark', '--primary-light', '--accent-color', '--accent-dark', '--accent-light', '--bg-light', '--border-color', '--black', '--white'];

        // Get the values
        $colors = $this->getCssVariables($variables, $cssFilePath);

        // Use the colors as needed
        return $colors;
    }

    private function getCssVariables($variables, $cssFilePath)
    {
        // Read the contents of the CSS file
        $cssContent = file_get_contents($cssFilePath);
        $values = [];

        foreach ($variables as $variable) {
            // Use a regular expression to match the CSS variable
            preg_match('/' . preg_quote($variable, '/') . '\s*:\s*([^;]+)\s*;/', $cssContent, $matches);
            // Add the matched value to the array or null if not found
            $values[$variable] = $matches[1] ?? null;
        }

        return $values;
    }

    public function themeCustomize(Request $request)
    {
        $theme = request()->route('theme');

        Theme::set($theme);
       
        $store = getStoreById(getCurrentStore());
        $testimonials = Testimonial::where('store_id', $store->id)->where('status', 1)->get();
        $brands = ProductBrand::where('store_id', $store->id)->where('status', 1)->get();
        $categories = Category::where('store_id', $store->id)->where('status', 1)->take(8)->get();
        $top_categories = Category::with('product_details')->where('store_id', $store->id)->where('status', 1)->take(3)->get();
        $bestseller_products = Product::where('store_id', $store->id)->where('status', 1)->take(10)->get();
        $blogs = Blog::where('store_id', $store->id)->take(10)->get();
        $productQuery = Product::where('store_id', $store->id)->where('product_type', null)->where('status', 1);

        $all_products = (clone $productQuery)->inRandomOrder()->limit(20)->get();
        return view('main_file', compact('testimonials', 'brands', 'categories', 'top_categories', 'bestseller_products', 'blogs', 'all_products'));

    }

}
