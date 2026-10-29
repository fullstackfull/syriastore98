<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Cookie;
use Session;
use Auth;
use App\Models\Utility;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\App;
use App\Models\AddOnManager;
use App\Facades\ModuleFacade as Module;
use Illuminate\Support\Facades\Cache;

class LanguageController extends Controller
{
    /**
     * F188 -- WHY THESE THREE HANDLERS NO LONGER FLUSH THE WHOLE CACHE.
     *
     * `Artisan::call('cache:clear')` stood as the FIRST statement of all three, and two of the
     * three routes are ANONYMOUS. Measured against `routes/web.php` and a brace-depth walk of its
     * groups:
     *
     *   GET change-language-store/{lang}  :1511  -> `web` only                    ANONYMOUS
     *   GET change-languages/{lang}       :387   -> `web` + `setlocate`           ANONYMOUS
     *   GET change-language/{lang}        :1308  -> inside `['auth','xss','setlocate','verified']`
     *
     * `web` carries no throttle (`bootstrap/app.php` appends guards, never a rate limiter). So an
     * unauthenticated GET -- repeatable, and needing no CSRF token because it is a GET -- emptied
     * the default cache store for every tenant at once. What lives there:
     *
     *   `gateway-return-claim:*`      the replay control for EVERY gateway return. Clearing it is
     *                                 what lets one capture be replayed into a second paid order:
     *                                 `claimGatewayReturn()` is `Cache::add()`, so a forgotten key
     *                                 is an un-taken claim.
     *   `paymera_pending_*`           a buyer mid-checkout loses the record their callback needs
     *                                 and meets "انتهت صلاحية جلسة الدفع" -- a captured payment
     *                                 with no order, i.e. F186's outcome reached by a stranger.
     *   `paymera_claim_*`, `paymera_lock_*`   F96's duplicate-order window, reopened.
     *
     * That is wider than the F188 row recorded (it named an operator enabling a module) and wider
     * than F186's analysis, which assumed a crash rather than a third party.
     *
     * WHAT REPLACES IT, AND WHAT THE FLUSH WAS AND WAS NOT FOR -- both corrected after review.
     *
     * `Utility::GetValByName()` caches each store's settings under `settings_store_<id>` with
     * `Cache::rememberForever()` (`Utility.php:245-259`) and nothing in the codebase forgets that
     * key -- a tree-wide search finds the string only at its own definition. So the forget below is
     * that one key, for the one store being changed, and it is ordered AFTER the `updateOrCreate`
     * calls, which the flush was not: the flush ran first, so a read landing in the gap re-cached
     * the OLD value *forever*. That ordering is a real improvement and it stands on its own.
     *
     * BUT AN EARLIER VERSION OF THIS NOTE SAID THE FLUSH WAS *THE ONLY WAY* THE `SITE_RTL` AND
     * `defult_language` ROWS BECAME VISIBLE, AND THAT IS FALSE. Review traced every caller:
     * nothing reads either name through `GetValByName()`. Its real callers read `CURRENCY`,
     * `CURRENCY_NAME`, `logo_dark` and the `is_*_enabled` flags. Direction and language come from
     * the UNCACHED `Utility::GetValueByName()` (`Utility.php:206-236`) at `layouts/app.blade.php:20`
     * and `layouts/theme_layout.blade.php:23`, from the uncached `Utility::Setting()` in
     * `layouts/guest.blade.php`, and from direct `Setting::where(...)` queries for the Flutter
     * app. So no renderer was ever served direction or labels from a cache the flush uniquely
     * reached. The forget is correct and cheap; it is not load-bearing, and claiming it was would
     * tell the next reader that removing it breaks a merchant's layout.
     *
     * `sideMenuCacheForget()` IS CURRENTLY A NO-OP, and that is also worth stating rather than
     * leaving as an implied control. It forgets `sidebar_menu_<id>` -- but a tree-wide search finds
     * that key ONLY in the three `Cache::forget()` calls inside the helper itself. Nothing in
     * `app/`, `resources/` or `packages/` ever WRITES it, `SidebarCustomization` contains no
     * `Cache::` call at all. The calls are kept, not deleted, because the per-user forget is the
     * right hook the moment a writer appears and because the helper also carries the `'all'` path
     * the module system uses -- but today they invalidate nothing. They dereference
     * `auth()->user()->type`, so they are called only where a user exists, never on the storefront
     * route.
     *
     * NOT CHANGED: `ModuleController`'s two `sideMenuCacheForget('all')` calls. Those are the
     * operator action the F188 row describes, they sit behind the module permission, and module
     * management is explicitly out of scope for this slice.
     *
     * =============================================================================================
     * WHAT THIS SLICE DOES **NOT** CLOSE, MEASURED ON A REAL DATABASE CACHE STORE.
     * =============================================================================================
     *
     * Review raised, and
     * `tests/Support/Security/bin/verify-model-cache-flush-scope-on-mysql.php` then MEASURED, that
     * the authenticated path still empties the whole cache in production -- for a reason that has
     * nothing to do with `cache:clear`:
     *
     *   - `App\Models\Setting` and `App\Models\User` both `use Cachable`, and
     *     laravel-model-caching flushes after every save (`ModelCaching.php:66-76`).
     *   - `Caching::cache()` tags the repository ONLY when the store is taggable
     *     (`Caching.php:95`). `ArrayStore` extends `TaggableStore`; `DatabaseStore` does not.
     *   - Production is `CACHE_STORE=database`.
     *
     * Measured on MariaDB 10.11 with the database store: ONE `Setting::updateOrCreate()` emptied
     * **4 of 4** seeded payment keys -- both `gateway-return-claim:*`, the `paymera_pending_*` and
     * the `paymera_claim_*`. The same write on the array store left all four. So the Feature test
     * beside this change passes because of `phpunit.xml`'s `CACHE_STORE=array`, and it could never
     * have said anything about production.
     *
     * SO: THE ANONYMOUS, UNTHROTTLED, CSRF-FREE WIPE IS CLOSED BY THIS SLICE -- neither anonymous
     * path writes a `Cachable` model (`changeLanquageStore` writes only a cookie and the session;
     * `changelanguage`'s writes are all inside `Auth::check()`). The AUTHENTICATED wipe is not
     * closed, and it is far wider than the language routes: `App\Models\Order` is `Cachable` too,
     * so in production any order save already empties every tenant's gateway-return claims. That
     * is a platform-wide defect in `Cachable` plus a non-taggable store, recorded separately, and
     * it is NOT what this change is. Do not read the comment above as "a language switch can no
     * longer empty the payment cache"; read it as "an anonymous one cannot".
     */
    public function changeLanquageStore($lang = '')
    {
        /* No flush and no forget: this handler writes NO setting at all -- only a cookie and the
           session, both of which `SetLocate`/`ThemeLanguage` read fresh on the next request. There
           is nothing cached for it to invalidate, and it is the anonymous storefront route. */
        Cookie::queue('LANGUAGE',$lang, 1440);
        Session::put(['LANGUAGE' => $lang]);
        return redirect()->back()->with('success', __('Language change successfully.'));
    }

    public function changeLanquage($lang)
    {
        // Cookie::queue('LANGUAGE',$lang, 1440);
        // Session::put(['LANGUAGE' => $lang]);
        $user = auth()->user();
        $user->language = $lang;
        $user->save();

        $settingQuery = Setting::query();
        if ($lang == 'ar' || $lang == 'he') {
            (clone $settingQuery)->updateOrCreate(
                [
                'name' => 'SITE_RTL',
                'store_id' => getCurrentStore()
            ],
                [
                    'value'         => 'on',
                    'name'          => 'SITE_RTL',
                    'store_id'      => getCurrentStore(),
                    'created_by'    => auth()->user()->id,
                ]
            );
        } else {
            (clone $settingQuery)->updateOrCreate(
                [
                'name' => 'SITE_RTL',
                'store_id' => getCurrentStore()
            ],
                [
                    'value'         => 'off',
                    'name'          => 'SITE_RTL',
                    'store_id'      => getCurrentStore(),
                    'created_by'    => auth()->user()->id,
                ]
            );
        }
        
        (clone $settingQuery)->updateOrCreate(
            [
            'name' => 'defult_language',
            'store_id' => getCurrentStore()
        ],
            [
                'value'         => $lang,
                'name'          => 'defult_language',
                'store_id'      => getCurrentStore(),
                'created_by'    => auth()->user()->id,
            ]
        );
        /* F188: forget exactly what was written, AFTER writing it. See the note above
           changeLanquageStore(). */
        Cache::forget('settings_store_' . getCurrentStore());
        sideMenuCacheForget();

        // Set the application locale to the selected language
        App::setLocale($lang);

        return redirect()->back()->with('success', __('Language change successfully.'));
    }

    public function changelanguage($lang = '')
    {
        Cookie::queue('LANGUAGE',$lang, 1440);
        Session::put(['LANGUAGE' => $lang]);
       
        if(Auth::check()){            
            $user       = Auth::user();
            $user->language = $lang;
            $user->save();
            $settingQuery = Setting::query();
            if ($lang == 'ar' || $lang == 'he') {
                (clone $settingQuery)->updateOrCreate(
                    [
                    'name' => 'SITE_RTL',
                    'store_id' => getCurrentStore()
                ],
                    [
                        'value'         => 'on',
                        'name'          => 'SITE_RTL',
                        'store_id'      => getCurrentStore(),
                        'created_by'    => auth()->user()->id ?? 0,
                    ]
                );
            } else {
                (clone $settingQuery)->updateOrCreate(
                    [
                    'name' => 'SITE_RTL',
                    'store_id' => getCurrentStore()
                ],
                    [
                        'value'         => 'off',
                        'name'          => 'SITE_RTL',
                        'store_id'      => getCurrentStore(),
                        'created_by'    => auth()->user()->id ?? 0,
                    ]
                );
            }
            
            (clone $settingQuery)->updateOrCreate(
                [
                'name' => 'defult_language',
                'store_id' => getCurrentStore()
            ],
                [
                    'value'         => $lang,
                    'name'          => 'defult_language',
                    'store_id'      => getCurrentStore(),
                    'created_by'    => auth()->user()->id ?? 0,
                ]
            );

            /* F188: inside the authenticated branch, because that is the one that wrote settings.
               An anonymous caller reaches neither the writes nor the invalidation. */
            Cache::forget('settings_store_' . getCurrentStore());
            sideMenuCacheForget();
        }
        // Set the application locale to the selected language
        //App::setLocale($lang);
        return redirect()->back()->with('success', __('Language change successfully.'));
    }

    public function manageLanguage($currantLang,$module='General')
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Language'))
        {
            $languages = Utility::languages();
            $settings = Setting::pluck('value', 'name')->toArray();
            if (!empty($settings['disable_lang'])) {
                $disabledLang = explode(',', $settings['disable_lang']);
            } else {
                $disabledLang = [];
            }

            if($module == 'General' ){
                $dir = base_path() . '/resources/lang/' . $currantLang;
                $arrLabel   = json_decode(file_get_contents($dir . '.json'));
            }else{
                $module = AddOnManager::where('module', $module)->first();
                if($module)
                {
                    $module= $module->module;
                    $this_module = Module::find($module);
                    $path =   $this_module->getPath();
                    $dir_module = $path.'/src/resources/lang/' . $currantLang;
                    $arrLabel   = json_decode(file_get_contents($dir_module . '.json'));
                    $dir = base_path() . '/resources/lang/' . $currantLang;
                }else{
                    return redirect()->back()->with('error', __('Please active this module.'));
                }
            }

            if(!is_dir($dir))
            {
                $dir = base_path() . '/resources/lang/en';
            }

            $arrFiles   = array_diff(
                scandir($dir), array(
                                    '..',
                                    '.',
                                )
            );
            $arrMessage = [];

            foreach($arrFiles as $file)
            {
                $fileName = basename($file, ".php");
                $fileData = $myArray = include $dir . "/" . $file;
                if(is_array($fileData))
                {
                    $arrMessage[$fileName] = $fileData;
                }
            }
            return view('language.index', compact('languages', 'currantLang', 'arrLabel', 'arrMessage','disabledLang','module'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function storeLanguageData(Request $request, $currantLang, $module = 'General')
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Language'))
        {
            $Filesystem = new Filesystem();
            $dir        = base_path() . '/resources/lang';
            if($module == 'General' ){
                $dir = base_path() . '/resources/lang/';
                if(!is_dir($dir))
                {
                    mkdir($dir);
                    chmod($dir, 0777);
                }
                $jsonFile = $dir . "/" . $currantLang . ".json";
            }else{
                $modules = AddOnManager::where('module',$module)->first();
                if(!empty($modules))
                {
                    $this_module = Module::find($modules->module);
                    $path =   $this_module->getPath();
                    $dir_module = $path.'/src/resources/lang/';
                    if(!is_dir($dir_module))
                    {
                        mkdir($dir_module);
                        chmod($dir_module, 0777);
                    }
                    $jsonFile = $dir_module . "/" . $currantLang . ".json";
                    $dir = base_path() . '/resources/lang/';
                    if(!is_dir($dir))
                    {
                        mkdir($dir);
                        chmod($dir, 0777);
                    }
                }else{
                    return redirect()->back()->with('error', __('Please active this module.'));
                }
            }

            if(isset($request->label) && !empty($request->label))
            {
                file_put_contents($jsonFile, json_encode($request->label));
            }

            $langFolder = $dir . "/" . $currantLang;

            if(!is_dir($langFolder))
            {
                mkdir($langFolder);
                chmod($langFolder, 0777);
            }
            if(isset($request->message) && !empty($request->message))
            {
                foreach($request->message as $fileName => $fileData)
                {
                    $content = "<?php return [";
                    $content .= $this->buildArray($fileData);
                    $content .= "];";
                    file_put_contents($langFolder . "/" . $fileName . '.php', $content);
                }
            }

            return redirect()->route('manage.language', [$currantLang])->with('success', __('Language save successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function disableLang(Request $request)
    {
        $settingQuery = Setting::query();
        if (\Auth::user()->type == 'super admin') {
            $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
            // $disablelang  = '';
            if ($request->mode == 'off') {
                if (!empty($settings['disable_lang'])) {
                    $disablelang = $settings['disable_lang'];
                    $disablelang = $disablelang . ',' . $request->lang;
                } else {
                    $disablelang = $request->lang;
                }
                
                    (clone $settingQuery)->updateOrCreate(
                        [
                            'name' => 'disable_lang',
                            'store_id' => getCurrentStore()
                        ],
                        [
                            'value'         => $disablelang,
                            'name'          => 'disable_lang',
                            'store_id'      => getCurrentStore(),
                            'created_by'    => auth()->user()->id,
                        ]
                    );

                $data['message'] = __('Language Disabled Successfully');
                $data['status'] = 200;
                return $data;
            } else {
                $disablelang = $settings['disable_lang'];
                $parts = explode(',', $disablelang);
                while (($i = array_search($request->lang, $parts)) !== false) {
                    unset($parts[$i]);
                }
                (clone $settingQuery)->updateOrCreate(
                    [
                        'name' => 'disable_lang',
                        'store_id' => getCurrentStore()
                    ],
                    [
                        'value'         => implode(',', $parts),
                        'name'          => 'disable_lang',
                        'store_id'      => getCurrentStore(),
                        'created_by'    => auth()->user()->id,
                    ]
                );
                
                $data['message'] = __('Language Enabled Successfully');
                $data['status'] = 200;
                return $data;
            }
        }
    }

    public function createLanguage()
    {
        return view('language.create');
    }

    public function storeLanguage(Request $request)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Create Language'))
        {               
            $Filesystem = new Filesystem();
            $langCode   = strtolower($request->code);
            $langDir    = base_path() . '/resources/lang/';
            $dir        = $langDir;
            if(!is_dir($dir))
            {
                mkdir($dir);
                chmod($dir, 0777);
            }
            $dir      = $dir . '/' . $langCode;
            $jsonFile = $dir . ".json";
            \File::copy($langDir . 'en.json', $jsonFile);

            if(!is_dir($dir))
            {
                mkdir($dir);
                chmod($dir, 0777);
            }
            $Filesystem->copyDirectory($langDir . "en", $dir . "/");

            // Specify the path to your JSON file
            $filePath = base_path('resources/lang/language.json');

            // Read the existing JSON file and decode its contents into an array
            $jsonContents = File::get($filePath);
            $data = json_decode($jsonContents, true);

            //append key wise data
            $data[$request->code] = $request->name;

            // Encode the updated array back to JSON format
            $jsonContentsUpdated = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            // Write the JSON data back to the file, preserving the existing contents
            File::put($filePath, $jsonContentsUpdated);


            return redirect()->route('manage.language', [$langCode])->with('success', __('Language successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function destroyLang($lang)
    {        
        if(auth()->user() && auth()->user()->isAbleTo('Delete Language'))
        {
            $default_lang = config('platform.default_language') ?? 'en';

            /*
             * REFUSE THE DEFAULT AND THE FALLBACK, SERVER-SIDE.
             *
             * The only guard on this destructive action used to be the view: `language/index.blade.php`
             * hides the delete control for `$default_lang` (:8 and :22). A hidden button is not a
             * control -- this route is a plain GET/DELETE by language code, so the protection was one
             * crafted URL away, and the handler itself happily deleted the language it then reassigned
             * every affected user TO (`User::where('language', 'LIKE', $lang)->update(['language' =>
             * $default_lang])` below), leaving those users pointing at a directory that no longer exists.
             *
             * `fallback_locale` is refused as well as the default, because they can differ:
             * `config/app.php:83` is `env('APP_FALLBACK_LOCALE', 'en')`, and deleting whatever it names
             * breaks translation resolution platform-wide, not just for the users reassigned.
             *
             * Surfaced by independent review of F48/R2. That change moved this method's
             * `env('default_language')` to `config('platform.default_language')`, which on a cached
             * deployment changes the value from `null` (so 'en' was always the protected one) to
             * whatever `.env` says -- so a deployment with `default_language=ar` would have started
             * offering 'en' for deletion. The hole is pre-existing; the change altered which language
             * was exposed to it, which is reason enough to close it here rather than note it.
             */
            $protected = array_filter(array_unique([
                $default_lang,
                config('app.fallback_locale'),
            ]));

            if(in_array($lang, $protected, true))
            {
                return redirect()->back()->with(
                    'error',
                    __('This language cannot be deleted: it is the default or the fallback language.')
                );
            }

            $langDir      = base_path() . '/resources/lang/';
            if(is_dir($langDir))
            {
                // remove directory and file
                Utility::delete_directory($langDir . $lang);

                // Specify the path to your JSON file
                $filePath = base_path('resources/lang/language.json');

                // Read the contents of the existing JSON file and decode it into an array
                $jsonContents = File::get($filePath);
                $data = json_decode($jsonContents, true);

                // Remove the data based on the key
                $keyToRemove = $lang;
                unset($data[$keyToRemove]);

                // Encode the updated array back to JSON format
                $jsonContentsUpdated = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                // Write the JSON data back to the file, replacing the existing contents
                File::put($filePath, $jsonContentsUpdated);


                unlink($langDir . $lang . '.json');
                // update user that has assign deleted language.
                User::where('language', 'LIKE', $lang)->update(['language' => $default_lang]);
            }
            return redirect()->route('manage.language', $default_lang)->with('success', __('Language Deleted Successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
