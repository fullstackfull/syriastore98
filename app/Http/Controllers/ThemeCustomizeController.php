<?php

namespace App\Http\Controllers;

use App\Models\{ThemeCustomize, ThemeCustomizeOrder};
use Illuminate\Http\Request;
use Qirolab\Theme\Theme;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Utility;

class ThemeCustomizeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Themes')) {
            $user = auth()->user();
            $plan = Plan::find($user->plan_id);
            $addons = Addon::where('status', '1')->pluck('theme_id')->toArray();
            if (!empty($plan->themes)) {
                $themes =  explode(',', $plan->themes);
            } else {
                $themes = ['stylique', 'greentic', 'techzonix'];
            }

            $currentTheme = (APP_THEME() ?? null); 
            if ($currentTheme) {
               array_unshift($themes, $currentTheme);
            } 
            $themes = array_unique($themes);
            return view('theme_customize.index', compact('themes', 'currentTheme', 'addons'));
        } else {
           return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function themePages(Request $request, $theme)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Themes')) {
            $path = base_path('themes/'.$theme.'/theme_json/pages.json');
            $page_json = json_decode(file_get_contents($path), true);

            return view('theme_customize.pages', compact('theme', 'page_json'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function themeCustomize(Request $request, $theme, $slug = null, $sub_slug = null)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Themes')) {
            $pagePath = base_path('themes/'.$theme.'/theme_json/pages.json');
            $page_json = json_decode(file_get_contents($pagePath), true);

            $path = base_path('themes/'.$theme.'/theme_json/'.$slug.'.json');
            $theme_json = json_decode(file_get_contents($path), true);
            $settings = getThemeSetting(getCurrentStore(), $theme);
            if (empty($sub_slug)) {
                $sub_slug = $theme_json['sections'][0]['slug'] ?? null;
            }

            return view('theme_customize.customize', compact('theme', 'slug', 'sub_slug', 'theme_json', 'page_json', 'settings'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function themeCustomizeUpdate(Request $request, $theme)
    {
        // if (auth()->user() && auth()->user()->isAbleTo('Edit Theme')) {
            $data = $request->except('_token');
          
            foreach ($data as $key => $values) {
                if (strpos($key, '_repeater') !== false && is_array($values)) { // Check if key ends with _repeater and is an array
                    foreach ($values as $subKey => $value) {
                        if (! empty($value['image']) && gettype($value['image']) == 'object') {
                            $filenameWithExt = $value['image']->getClientOriginalName();
                            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                            $extension = $value['image']->getClientOriginalExtension();
                            $fileNameToStore = $filename.'_'.time().'.'.$extension;

                            
                            $upload = Utility::upload_file($value, 'image', $fileNameToStore, 'uploads/'.$theme);
                            if ($upload['flag'] == 1) {
                                $url = $upload['url'];
                                $data[$key][$subKey]['image'] = $url; // Assign the URL back to the corresponding key
                            } else {
                                return response()->json(['msg' => 'error', 'error' => $upload['msg']]);
                            }
                        }
                        if (! empty($value['background_image']) && gettype($value['background_image']) == 'object') {
                            $filenameWithExt = $value['background_image']->getClientOriginalName();
                            $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                            $extension = $value['background_image']->getClientOriginalExtension();
                            $fileNameToStore = $filename.'_'.time().'.'.$extension;

                            
                            $upload = Utility::upload_file($value, 'background_image', $fileNameToStore, 'uploads/'.$theme);
                            if ($upload['flag'] == 1) {
                                $url = $upload['url'];
                                $data[$key][$subKey]['background_image'] = $url; // Assign the URL back to the corresponding key
                            } else {
                                return response()->json(['msg' => 'error', 'error' => $upload['msg']]);
                            }
                        }
                    }
                    $data[$key] = json_encode($data[$key]); // Convert the repeater array to JSON
                }

                if ((strpos($key, '_image') !== false || strpos($key, '_logo') !== false || strpos($key, '_favicon') !== false) && is_object($values)) { // Check if key ends with _repeater and is an array
                    $image_data = $key;
                    $filenameWithExt = $request->$image_data->getClientOriginalName();
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                    $extension = $request->$image_data->getClientOriginalExtension();
                    $fileNameToStore = $filename.'_'.time().'.'.$extension;

                    $uplaod = Utility::upload_file($request, $image_data, $fileNameToStore, 'uploads/'.$theme);
                    if ($uplaod['flag'] == 1) {
                        $url = $uplaod['url'];
                        $data[$image_data] = $url;
                    } else {
                        return response()->json(['msg' => 'error', 'error' => $uplaod['msg']]);
                    }
                }
            }

            /*
             * PHASE 03 M3-C. One controlled write instead of a per-key loop into the table.
             *
             * WHAT THIS REPLACES, measured at M3-A and recorded as
             * docs/discovery/03-FEATURE-MATRIX.md:223 (HIGH/P0, *Theme settings write
             * authorization*):
             *
             *   - the `isAbleTo('Edit Theme')` check at the top of this method is COMMENTED OUT,
             *     while all four READ actions in this controller check -- an INVERTED guard, the
             *     reads locked and the writes open. Uncommenting it is not the fix: `Edit Theme`
             *     does not exist as a permission and `Manage Themes` is held by no role and no user
             *     in the fixture, so the naive repair locks every merchant out of their own editor.
             *   - there was no allowlist, so `$key` could be any request key and `$value` any
             *     string, and 43 settings reach the storefront through unescaped `{!! !!}` output.
             *   - the store came from `getCurrentStore()`, which hands a platform operator the
             *     literal store 1 -- which belongs to a MERCHANT.
             *
             * `ThemeEditorGate` tests the operator first (Laratrust's `isAbleTo()` alone REFUSES
             * the operator), proves store ownership, and refuses an operator who has named no
             * store. `ThemeSettingValidator` checks each value against the type the theme itself
             * declares and REJECTS rather than silently rewriting. The cache is flushed after
             * commit.
             */
            /*
             * THE FORM'S CONTROL KEYS ARE NOT SETTINGS, and writing them was a latent defect of its
             * own. `resources/views/theme_customize/customize.blade.php` posts five bare names
             * alongside the real fields:
             *
             *     slug              :192  a hidden field carrying the section key
             *     image             :289, :294, :341, :345  the file inputs' own names
             *     background_image  :356  the same
             *     menu, button_link       sub-inputs of composite field types
             *
             * None is a composed `{section}_{setting}` name, so none is a declared setting. Before
             * M3-C the per-key loop wrote them into `theme_customizes` as junk rows, which
             * `getThemeSetting()` would then merge into `$themeSettings['slug']` and friends. The
             * fixture has none only because it was seeded by script rather than through the form.
             *
             * They are dropped here rather than rejected, because reporting five "settings not
             * saved" on every save would be noise that teaches a merchant to ignore the warning
             * that matters.
             */
            $data = collect($data)
                ->except(['slug', 'image', 'background_image', 'menu', 'button_link'])
                ->all();

            $service = app(\App\Services\Theme\ThemeConfigurationService::class);
            $gate = new \App\Support\Theme\ThemeEditorGate();

            $written = $service->writeSettings(
                $gate->storeIdForSelfService(),
                (string) $theme,
                $data
            );

            if ($written === null) {
                /*
                 * The refusal reason is LOGGED, not shown. It is written for an engineer ("'x' is
                 * not a theme this installation ships"), it is English-only in a bilingual admin,
                 * and appending it to a translated string would hand an Arabic merchant a
                 * half-Arabic sentence. It also says more about the installation than a refused
                 * caller needs to know.
                 */
                \Log::warning('theme settings write refused', [
                    'reason' => $service->refusal(),
                    'theme' => $theme,
                    'user_id' => auth()->id(),
                ]);

                return redirect()->back()->with('error', __('Permission denied.'));
            }

            $rejected = $service->rejected();

            if ($rejected !== []) {
                /*
                 * Partial success is reported as such. Saying "Setting Saved Successfully" while
                 * silently dropping fields is how a merchant comes to believe a value is stored
                 * when it is not -- the same shape as the stale Flutter social-links defect.
                 */
                /*
                 * `__()` with a key present in BOTH en.json and ar.json, not `trans_choice` with an
                 * inline string. An inline `trans_choice` argument is not a translation key, so it
                 * renders in English for an Arabic merchant -- and `trans_choice` appears nowhere
                 * else in app/. The phrasing carries no count, which also sidesteps Arabic plural
                 * agreement rather than getting it wrong.
                 */
                return redirect()->back()
                    ->with('success', __('Setting Saved Successfully.'))
                    ->with('error', __('The following settings were not saved: :fields',
                        ['fields' => implode(', ', array_keys($rejected))]));
            }

            return redirect()->back()->with('success', __('Setting Saved Successfully.'));

        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    public function imageFileGet(Request $request)
    {
        if ($request->has('imgSrc')) {
            $imgSrc = $request->input('imgSrc');
            $imgUrl = get_file($imgSrc);

            return response()->json($imgUrl);
        } else {
            return response()->json(['error' => __('imgSrc parameter missing')], 400);
        }
    }

    public function changeOrder(Request $request, $theme, $slug = null, $sub_slug = null)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Themes')) {
            $pagePath = base_path('themes/'.$theme.'/theme_json/pages.json');
            $page_json = json_decode(file_get_contents($pagePath), true);
            
            if (empty($sub_slug)) {
                $sub_slug = $theme_json['sections'][0]['slug'] ?? null;
            }

            $orders = null;
            $theme_order = ThemeCustomizeOrder::where('theme_id', $theme)->where('store_id', getCurrentStore())->where('page_slug', $slug)->first();
            if (! empty($theme_order)) {
                $orders = $theme_order->orders;
            }

            return view('theme_customize.order', compact('theme', 'slug', 'sub_slug', 'page_json', 'orders'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function updateOrder(Request $request, $theme)
    {      
        ThemeCustomizeOrder::updateOrCreate(['theme_id' => $theme, 'page_slug' => $request->page_slug, 'store_id' => getCurrentStore()], ['orders' => $request->orders]);
        return redirect()->back()->with('success', __('Order change successfully.'));
    }

    public function makeActiveTheme(Request $request)
    {

        if (isset($request->theme_id)) {
            Store::where('id', getCurrentStore())->update(['theme_id' => $request->theme_id]);
        }
        return redirect()->back()->with('success', __('Theme active set successfully'));
    }
}
