<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use App\Models\Utility;
use App\Models\{Customer, Country, Order, PlanOrder, Plan, PlanCoupon, PlanRequest, Store, Setting, User, OrderBillingDetail, PixelFields, Page, Cart, City, DeliveryAddress, State};
use App\Models\Faq;
use App\Models\Category;
use App\Models\BlogCategory;
use App\Models\Blog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\{FlashSale, ProductQuestion, Testimonial, Wishlist, TaxOption};
use Qirolab\Theme\Theme;
use App\Http\Controllers\Api\ApiController;
use Shetabit\Visitor\VisitorFacade as Visitor;
use App\Facades\ModuleFacade as Module;
use App\Models\AddOnManager;
use App\Models\ProductBrand;
use App\Http\Controllers\OfertemagController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    public function __construct(Request $request)
    {

        if (!file_exists(storage_path() . '/installed')) {
            header('location:install');
            exit;
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function Landing()
    {
        if (auth()->user()) {
            return redirect('dashboard');
        }
        $uri = url()->full();
        $segments = explode('/', str_replace('' . url('') . '', '', $uri));
        $segments = $segments[1] ?? null;
        $local = parse_url(config('app.url'))['host'];
        // Get the request host
        $remote = request()->getHost();
        // Get the remote domain
        // remove WWW
        $remote = str_replace('www.', '', $remote);
        $subdomain = Setting::where('name', 'subdomain')->where('value', $remote)->first();
        $domain = Setting::where('name', 'domains')->where('value', $remote)->first();

        $enable_subdomain = '';
        $enable_domain = '';

        if ($subdomain || $domain) {
            if ($subdomain) {
                $enable_subdomain = Setting::where('name', 'enable_subdomain')->where('value', 'on')->where('store_id', $subdomain->store_id)->first();

            }

            if ($domain) {
                $enable_domain = Setting::where('name', 'enable_domain')->where('value', 'on')->where('store_id', $domain->store_id)->first();
            }
        }
        if ($enable_domain || $enable_subdomain) {

            if ($subdomain) {
                $enable_subdomain = Setting::where('name', 'enable_subdomain')->where('value', 'on')->where('store_id', $subdomain->store_id)->first();
                if ($enable_subdomain) {
                    $admin = User::find($enable_subdomain->created_by);

                    if ($enable_subdomain->value == 'on' && $enable_subdomain->store_id == $admin->current_store) {
                        $store = Store::find($admin->current_store);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } elseif ($enable_subdomain->value == 'on' && $enable_subdomain->store_id != $admin->current_store) {
                        $store = Store::find($enable_subdomain->store_id);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } else {
                        return $this->storeSlug($segments);
                    }
                }
            }

            if ($domain) {
                $enable_domain = Setting::where('name', 'enable_domain')->where('value', 'on')->where('store_id', $domain->store_id)->first();

                if ($enable_domain) {
                    $admin = User::find($enable_domain->created_by);

                    if ($enable_domain->value == 'on' && $enable_domain->store_id == $admin->current_store) {
                        $store = Store::find($admin->current_store);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } elseif ($enable_domain->value == 'on' && $enable_domain->store_id != $admin->current_store) {
                        $store = Store::find($enable_domain->store_id);
                        if ($store) {
                            request()->route()->setParameter('storeSlug', $store->slug);
                            return $this->storeSlug($store->slug);
                        }
                    } else {
                        return $this->storeSlug($segments);
                    }
                }
            }
        } else {
            $settings = getSuperAdminAllSetting();
            if (isset($settings['display_landing']) && $settings['display_landing'] == 'on') {
                Artisan::call('package:migrate LandingPage');
                Artisan::call('package:seed LandingPage');
                return view('landing-page::layouts.landingpage');
            } else {
                return redirect('login');
            }
        }
    }

    public function index()
    {
        $user = auth()->user();
        Utility::userDefaultData($user->id);

        if ($user->type == 'super admin') {
            Utility::defaultEmail();
            $data = $this->handleSuperAdmin($user);

            return view('superadmin.dashboard', $data);
        } else {
            $data = $this->handleRegularUser($user);
            return view('dashboard', $data);
        }
    }

    private function handleSuperAdmin($user)
    {
        $user['total_user'] = $user->countCompany();
        $user['total_orders'] = PlanOrder::total_orders();
        $user['total_plan'] = Plan::total_plan();
        $chartData = $this->getOrderChart(['duration' => 'week']);
        $topAdmins = $user->createdAdmins()
            ->with('stores')
            ->withCount('stores')
            ->orderBy('stores_count', 'desc')
            ->limit(5)
            ->get();

        $visitors = DB::table('shetabit_visits')->whereNotNull('store_id')->pluck('store_id')->toArray();
        $visitors = array_count_values($visitors);
        arsort($visitors);
        $visitors = array_slice($visitors, 0, 5, true);

        $plan_order = Plan::most_purchese_plan();
        $coupons = PlanCoupon::get();
        $maxValue = 0;
        $couponName = '';
        foreach ($coupons as $coupon) {
            $max = $coupon->used_coupon();
            if ($max > $maxValue) {
                $maxValue = $max;
                $couponName = $coupon->name;
            }
        }

        $allStores = Order::select('store_id', DB::raw('SUM(final_price) as total_amount'))
            ->groupBy('store_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();
        $plan_requests = PlanRequest::count();

        $data = compact('user', 'chartData', 'couponName', 'plan_order', 'plan_requests', 'allStores', 'topAdmins', 'visitors');
        return $data;
    }

    private function handleRegularUser($user)
    {
        $todayStart = Carbon::today();
        $todayEnd = Carbon::now();
        $yesterdayStart = Carbon::yesterday();
        $yesterdayEnd = Carbon::yesterday()->endOfDay();
        $productQuery = Product::where('product_type', null)->where('store_id', getCurrentStore());
        $orderQuery = Order::where('store_id', getCurrentStore());

        $totalproduct = (clone $productQuery)->count();
        $today_product = (clone $productQuery)->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $productPer = $this->calculatePercentageToday($today_product, $totalproduct);

        $totle_order = (clone $orderQuery)->count();
        $customerQuery = Customer::where('store_id', getCurrentStore());
        $totle_customers = (clone $customerQuery)->where('store_id', getCurrentStore())->count();
        $today_customers = (clone $customerQuery)->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $customerPer = $this->calculatePercentageToday($today_customers, $totle_customers);

        $totle_cancel_order = (clone $orderQuery)->where('delivered_status', 2)->count();

        $total_revenues = (clone $orderQuery)->where(function ($query) {
            $query->where(function ($subquery) {
                $subquery->where('delivered_status', '!=', 2)
                    ->where('delivered_status', '!=', 3);
            })->orWhere('return_status', '!=', 2);
        })->sum('final_price');

        $topSellingProductIds = (clone $orderQuery)->pluck('product_id')
            ->flatMap(function ($productIds) {
                return explode(',', $productIds);
            })
            ->map(function ($productId) {
                return (int) $productId;
            })
            ->groupBy(function ($productId) {
                return $productId;
            })
            ->map(function ($group) {
                return $group->count();
            })
            ->sortDesc()
            ->take(5)
            ->keys();

        $topSellingProducts = (clone $productQuery)->whereIn('id', $topSellingProductIds)->get();
        $theme_name = APP_THEME() ? APP_THEME() : env('DATA_INSERT_APP_THEME');
        $out_of_stock_threshold = Utility::GetValueByName('out_of_stock_threshold', getCurrentStore());
        $latests = (clone $productQuery)->orderBy('created_at', 'Desc')->limit(3)->get();

        $orderCountsToday = $this->getOrderCounts($orderQuery, $todayStart, $todayEnd);
        $orderCounts = $this->getOrderCounts($orderQuery);

        //$orderCountsYesterday = $this->getOrderCounts($orderQuery, $yesterdayStart, $yesterdayEnd);

        $totalOrderPer = $this->calculatePercentageToday($orderCountsToday['total'], $orderCounts['total']);
        $pendingOrderPer = $this->calculatePercentageToday($orderCountsToday['pending'], $orderCounts['pending']);
        $completeOrderPer = $this->calculatePercentageToday($orderCountsToday['complete'], $orderCounts['complete']);
        $deliveredOrderPer = $this->calculatePercentageToday($orderCountsToday['delivered'], $orderCounts['delivered']);
        $cancelOrderPer = $this->calculatePercentageToday($orderCountsToday['cancel'], $orderCounts['cancel']);
        $returnOrderPer = $this->calculatePercentageToday($orderCountsToday['return'], $orderCounts['return']);
        $shippedOrderPer = $this->calculatePercentageToday($orderCountsToday['shipped'], $orderCounts['shipped']);


        $pending_order = $orderCounts['pending'];
        $delivered_order = $orderCounts['delivered'];
        $cancel_order = $orderCounts['cancel'];
        $return_order = $orderCounts['return'];
        $confirmed_order = $orderCounts['complete'];
        $shipped_order = $orderCounts['shipped'];
        $new_orders = $orderQuery->orderBy('id', 'DESC')->limit(4)->get();
        $chartData = $this->getOrderChart(['duration' => 'week']);

        $store = getStoreById(getCurrentStore());
       
        $slug = $store->slug;
        $storage_limit = 0;
        $users = User::find($user->id);
        $plan = null;
        if ($users) {
            $plan = Plan::find($users->plan_id);
            if ($plan && $plan->storage_limit > 0) {
                $storage_limit = ($user->storage_limit / $plan->storage_limit) * 100;
            }
        }


        $theme_url = $this->getThemeUrl($store);

        $data = compact(
            'totalproduct',
            'totle_order',
            'totle_customers',
            'latests',
            'new_orders',
            'chartData',
            'theme_url',
            'store',
            'storage_limit',
            'users',
            'plan',
            'topSellingProducts',
            'total_revenues',
            'totle_cancel_order',
            'out_of_stock_threshold',
            'theme_name',
            'pending_order',
            'delivered_order',
            'cancel_order',
            'return_order',
            'confirmed_order',
            'totalOrderPer',
            'pendingOrderPer',
            'completeOrderPer',
            'deliveredOrderPer',
            'cancelOrderPer',
            'returnOrderPer',
            'customerPer',
            'productPer',
            'shippedOrderPer',
            'shipped_order'
        );
        return $data;
    }

    private function getOrderCounts($orderQuery, $start = null, $end = null)
    {
        if (!empty($start) && !empty($end)) {
            return [
                'total' => (clone $orderQuery)->whereBetween('created_at', [$start, $end])->count(),
                'pending' => (clone $orderQuery)->where('delivered_status', 0)->whereBetween('created_at', [$start, $end])->count(),
                'delivered' => (clone $orderQuery)->where('delivered_status', 1)->whereBetween('created_at', [$start, $end])->count(),
                'complete' => (clone $orderQuery)->where('delivered_status', 4)->whereBetween('created_at', [$start, $end])->count(),
                'cancel' => (clone $orderQuery)->where('delivered_status', 2)->whereBetween('created_at', [$start, $end])->count(),
                'return' => (clone $orderQuery)->where('delivered_status', 3)->whereBetween('created_at', [$start, $end])->count(),
                'shipped' => (clone $orderQuery)->where('delivered_status', 6)->whereBetween('created_at', [$start, $end])->count(),
            ];
        } else {
            return [
                'total' => (clone $orderQuery)->count(),
                'pending' => (clone $orderQuery)->where('delivered_status', 0)->count(),
                'delivered' => (clone $orderQuery)->where('delivered_status', 1)->count(),
                'complete' => (clone $orderQuery)->where('delivered_status', 4)->count(),
                'cancel' => (clone $orderQuery)->where('delivered_status', 2)->count(),
                'return' => (clone $orderQuery)->where('delivered_status', 3)->count(),
                'shipped' => (clone $orderQuery)->where('delivered_status', 6)->count(),
            ];
        }
    }

    private function calculatePercentageToday($todayCount, $allCount)
    {
        if ($allCount == 0) {
            return $todayCount > 0 ? 100 : 0;
        }
        $percentage = (($todayCount - $allCount) / $allCount) * 100;
        if ($percentage > 0) {
            return '+ ' . number_format($percentage, 2);
        } else {
            return number_format($percentage, 2);
        }

    }

    public static function getThemeUrl($store)
    {
        $enable_storelink = Utility::GetValueByName('enable_storelink', $store->id ?? getCurrentStore());
        $enable_domain = Utility::GetValueByName('enable_domain', $store->id ?? getCurrentStore());
        $enable_subdomain = Utility::GetValueByName('enable_subdomain', $store->id ?? getCurrentStore());
        $domains = Utility::GetValueByName('domains', $store->id ?? getCurrentStore());
        $subdomain = Utility::GetValueByName('subdomain', $store->id ?? getCurrentStore());

        if ($enable_domain == 'on') {
            return 'https://' . $domains;
        } elseif ($enable_subdomain == 'on') {
            return 'https://' . $subdomain;
        } elseif ($enable_storelink) {
            return route('landing_page', $store->slug);
        } else {
            return route('landing_page', $store->slug);
        }
    }

    public function getOrderChart($arrParam)
    {
        $user = auth()->user();
        if (!$user) {
            return redirect()->route('login')->with('message', __('You have been logged out.'));
        }
        $store = getStoreById($user->current_store);

        if (!$store) {
            if (auth()->check()) {
                auth()->logout();
            }

            return redirect()->route('login')->with('message', __('You have been logged out.'));
        }
        
        $arrDuration = [];
        if ($arrParam['duration']) {
            if ($arrParam['duration'] == 'week') {
                $previous_week = strtotime('-1 week +1 day');

                for ($i = 0; $i < 7; $i++) {
                    $arrDuration[date('Y-m-d', $previous_week)] = date('d-M', $previous_week);
                    $previous_week = strtotime(date('Y-m-d', $previous_week) . ' +1 day');
                }
            }
        }
        $arrTask = [];
        $arrTask['label'] = [];
        $arrTask['data'] = [];
        $registerTotal = '';
        $newguestTotal = '';
        foreach ($arrDuration as $date => $label) {
            if (auth()->user()->type == 'admin') {
                $data = Order::select(\DB::raw('count(*) as total'))                    
                    ->where('store_id', getCurrentStore())
                    ->whereDate('created_at', '=', $date)
                    ->first();

                $registerTotal = Customer::select(\DB::raw('count(*) as total'))                    
                    ->where('store_id', getCurrentStore())
                    ->where('regiester_date', '!=', null)
                    ->whereDate('regiester_date', '=', $date)
                    ->first();

                $newguestTotal = Customer::select(\DB::raw('count(*) as total'))
                    ->where('store_id', getCurrentStore())
                    ->where('regiester_date', '=', null)
                    ->whereDate('last_active', '=', $date)
                    ->first();
            } else {
                $data = PlanOrder::select(\DB::raw('count(*) as total'))
                    ->whereDate('created_at', '=', $date)
                    ->first();
            }

            $arrTask['label'][] = $label;
            $arrTask['data'][] = $data ? $data->total : 0; // Check if $data is not null

            if (auth()->user()->isAbleTo('Manage Dashboard')) {
                $arrTask['registerTotal'][] = $registerTotal ? $registerTotal->total : 0; // Check if $registerTotal is not null
                $arrTask['newguestTotal'][] = $newguestTotal ? $newguestTotal->total : 0; // Check if $newguestTotal is not null
            }
        }

        return $arrTask;
    }

    public function landing_page($storeSlug)
    {
        $uri = url()->full();
        $segments = explode('/', str_replace(url(''), '', $uri));
        $segments = $segments[1] ?? null;

        $local = parse_url(config('app.url'))['host'];
        $remote = str_replace('www.', '', request()->getHost());

        // Cache the settings
        $settings = Setting::whereIn('name', ['subdomain', 'domains', 'enable_subdomain', 'enable_domain'])
            ->where('value', $remote)
            ->get()
            ->keyBy('name');

        $subdomainSetting = $settings->get('subdomain');
        $domainSetting = $settings->get('domains');

        $enable_subdomain = null;
        $enable_domain = null;

        if ($subdomainSetting) {
            $enable_subdomain = Setting::where('name', 'enable_subdomain')
                ->where('value', 'on')
                ->where('store_id', $subdomainSetting->store_id)
                ->first();
        }

        if ($domainSetting) {
            $enable_domain = Setting::where('name', 'enable_domain')
                ->where('value', 'on')
                ->where('store_id', $domainSetting->store_id)
                ->first();
        }

        $storeSlugToReturn = $segments;

        if ($enable_subdomain) {
            $admin = User::find($enable_subdomain->created_by);
            if ($enable_subdomain->value == 'on' && $enable_subdomain->store_id == $admin->current_store) {
                $store = Store::find($admin->current_store);
                if ($store) {
                    return $this->storeSlug($store->slug);
                }
            }
        }

        if ($enable_domain) {
            $admin = User::find($enable_domain->created_by);
            if ($enable_domain->value == 'on' && $enable_domain->store_id == $admin->current_store) {
                $store = Store::find($admin->current_store);
                if ($store) {
                    return $this->storeSlug($store->slug);
                }
            }
        }

        return $this->storeSlug($storeSlugToReturn);
    }


    private function storeSlug($storeSlug)
    {
        if (!empty($storeSlug)) {
            $store = getStore($storeSlug);
            if (!view()->exists('main_file') || !$store) {
                return redirect()->back()->with('error', __("Store Not Found."));
            }
            $store = getStore($storeSlug);
            $testimonials = Testimonial::where('store_id', $store->id)->where('status', 1)->get();
            $brands = ProductBrand::where('store_id', $store->id)->where('status', 1)->get();
            $categories = Category::where('store_id', $store->id)->where('status', 1)->take(8)->get();
            $top_categories = Category::with('product_details')->where('store_id', $store->id)->where('status', 1)->take(3)->get();
            $bestseller_products = Product::where('store_id', $store->id)->where('status', 1)->take(10)->get();
            $blogs = Blog::where('store_id', $store->id)->take(10)->get();
            $productQuery = Product::where('store_id', $store->id)->where('product_type', null)->where('status', 1);

            $all_products = (clone $productQuery)->inRandomOrder()->limit(20)->get();
            return view('main_file', compact('testimonials', 'brands', 'categories', 'top_categories', 'bestseller_products', 'blogs', 'all_products'));
        } else {
            return abort('403', 'The Link is not active.');
        }

    }

    public function faqs_page(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }

        $faqs = Faq::where('store_id', $store->id)->get();

        return view('front_end.pages.faq', compact('faqs'));
    }

    public function about_page(Request $request, $storeSlug)
    {
        return view('front_end.pages.about');
    }

    public function blog_page(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
        
        $topNavItems = [];
       
        $BlogCategory = BlogCategory::where('store_id', $store_id)->get()->pluck('name', 'id');
        $BlogCategory->prepend('All', '0');

        $blogs = Blog::where('store_id', $store_id)->paginate(6);
       
        return view('front_end.pages.blog', compact('BlogCategory', 'store', 'blogs'));
    }

    public function article_page(Request $request, $storeSlug, $blogSlug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
       
        $blog = Blog::where('slug', $blogSlug)->where('store_id', $store_id)->first();
        $home_blogs = Blog::where('store_id', $store_id)->get();
        if (empty($blog)) {
            abort(404);
        }

        $datas = Blog::where('store_id', $store_id)->inRandomOrder()
            ->limit(3)
            ->get();

        $l_articles = Blog::where('store_id', $store_id)->inRandomOrder()
            ->limit(5)
            ->get();

        $BlogCategory = BlogCategory::where('store_id', $store_id)->get()->pluck('name', 'id');
        $BlogCategory->prepend('All Products', '0');
        $homeproducts = Product::where('product_type', null)->where('store_id', $store_id)->get();
       
        $blogs = Blog::where('store_id', $store_id)->where('category_id', $blog->category_id)->get();

        return view('front_end.pages.article', compact('blog', 'datas', 'l_articles', 'BlogCategory', 'homeproducts', 'blogs'));

    }

    public function product_page(Request $request, $storeSlug, $categorySlug = null)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }

        $store_id = $store->id;
        $slug = $store->slug;
        
        $category_ids = [];
        $brand_ids = $brand_select = [];
        if ($categorySlug) {
            $category_ids = Category::where(function ($query) use ($categorySlug) {
                $query->where('slug', 'Like', "%$categorySlug%")->orWhere('name', 'Like', "%$categorySlug%");
            })->where('store_id', $store_id)->pluck('id')->toArray();
            if (!$category_ids) {
                $brand_select = $brand_ids = ProductBrand::where('slug', 'like', "%$categorySlug%")->where('store_id', $store_id)->pluck('id')->toArray();
            }
        }
       
        $filter_product = $request->get('filter_product') ?? 'all';
        $MainCategoryList = Category::where('status', 1)->where('store_id', $store_id)->get();
        $brands = ProductBrand::where('status', 1)->where('store_id', $store_id)->get();
       
        $main_category = $request->main_category;
        $category_slug = $request->category_slug;
        $product_brand = $request->brands;
        
        if (!empty($product_brand)) {
            $brand_select = ProductBrand::where('id', $product_brand)->pluck('id')->toArray();
        }
        
        /* For Filter */
        $min_price = 0;
        $max_price = Product::where('variant_product', 0)->orderBy('price', 'DESC')->where('product_type', null)->where('store_id', $store_id)->first();
        $max_price = !empty($max_price->price) ? $max_price->price : '0';

        $product_tag = implode(',', $category_ids);
        $product_brand = implode(',', $brand_ids);
        $filter_tag = $MainCategoryList;

        $compact = ['slug', 'MainCategoryList', 'filter_tag', 'min_price', 'max_price', 'brands', 'brand_select', 'product_tag', 'product_brand', 'store', 'category_ids', 'filter_product'];

        return view('front_end.pages.product-list', compact($compact));
    }

    public function product_page_filter(Request $request, $storeSlug)
    {
        $store = getStore($storeSlug);  
        if (!$store) {
            $data['product_count'] = 0;
            $data['status'] = false;
            $data['html'] = '';
            return response()->json($data);
        }     
        $page = $request->page ?? 1;

        // Clean parameters
        $filter_value = $request->get('filter_product') !== 'undefined' ? $request->get('filter_product') : null;
        $product_tag = $request->product_tag !== 'undefined' ? $request->product_tag : null;
        $product_brand = $request->product_brand !== 'undefined' ? $request->product_brand : null;
        $min_price = $request->min_price !== 'undefined' ? $request->min_price : 0;
        $max_price = $request->max_price !== 'undefined' ? $request->max_price : null;

        if (!empty($product_tag)) {
            $product_tag = explode(',', $product_tag);
        }

        if (!empty($product_brand)) {
            $product_brand = explode(',', $product_brand);
        }

        $products_query = Product::where('product_type', null)
            ->where('store_id', $store->id)
            ->where('status', 1);


        if (!empty($product_tag)) {
            $products_query->whereIn('category_id', $product_tag);
        }

        if (!empty($product_brand)) {
            $products_query->whereIn('brand_id', $product_brand);
        }

        if (!empty($max_price)) {
            $products_query->where(function ($query) use ($min_price, $max_price) {
                $query->whereBetween('price', [$min_price, $max_price]);
            });
        }

        if (!empty($filter_value)) {
            switch ($filter_value) {
                case 'trending':
                    $products_query->where('trending', 1);
                    break;
                case 'title-ascending':
                    $products_query->orderBy('name', 'asc');
                    break;
                case 'title-descending':
                    $products_query->orderBy('name', 'desc');
                    break;
                case 'price-ascending':
                    $products_query->orderBy('price', 'asc');
                    break;
                case 'price-descending':
                    $products_query->orderBy('price', 'desc');
                    break;
                case 'created-ascending':
                    $products_query->orderBy('created_at', 'asc');
                    break;
                case 'created-descending':
                    $products_query->orderBy('created_at', 'desc');
                    break;
            }
        }
        $product_count = $products_query->count();
        $products = $products_query->paginate(12);

       
        $currentDateTime = now()->format('Y-m-d H:i:s A');

        $tax_option = TaxOption::where('store_id', $store->id)
            ->pluck('value', 'name')
            ->toArray();

        $data['product_count'] = $product_count;
        $data['status'] = true;
        $data['html'] = view('front_end.pages.product_list_filter', compact(
            'tax_option',
            'storeSlug',
            'products',
            'currentDateTime',
            'product_count'
        ))->render();
        return response()->json($data);
    }

    public function product_detail(Request $request, $storeSlug, $product_slug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id = $store->id;
        $slug = $store->slug;
        
        $storeId = $store->id;
        $product = Product::where('slug', $product_slug)->where('store_id', $store_id)->first();
        
        if (!$product) {
            return redirect()->back()->with('error', __('Product not found.'));
        }
        $id = $product->id;
       
        
        $Stocks = ProductVariant::where('product_id', $id)->first();
        if ($Stocks) {
            $minPrice = ProductVariant::where('product_id', $id)->min('price');
            $maxPrice = ProductVariant::where('product_id', $id)->max('price');

            $min_vprice = ProductVariant::where('product_id', $id)->min('variation_price');
            $max_vprice = ProductVariant::where('product_id', $id)->max('variation_price');

            $mi_price = !empty($minPrice) ? $minPrice : $min_vprice;
            $ma_price = !empty($maxPrice) ? $maxPrice : $max_vprice;
        } else {
            $mi_price = 0;
            $ma_price = 0;
        }    
        
       
        $question = ProductQuestion::where('product_id', $id)->where('store_id', $storeId)->get();

        $flashsales = FlashSale::where('store_id', $storeId)->orderBy('created_at', 'Desc')->get();

        $setting = getAdminAllSetting();
        $defaultTimeZone = isset($setting['defult_timezone']) ? $setting['defult_timezone'] : 'Asia/Kolkata';
        date_default_timezone_set($defaultTimeZone);
        $currentDateTime = date('Y-m-d H:i:s A');

        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select country', ' ');
        $response = Cart::cart_list_cookie($request->all(), $store->id);
        $response = json_decode(json_encode($response));
       
        if (module_is_active('PreOrder')) {
            $customer = auth('customers')->user() ?? null;
            $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
            if (isset($customer) && isset($product) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on' && (($product->variant_product == 0 && $product->track_stock == 0 && $product->stock_status == 'out_of_stock') || ($product->variant_product == 0 && $product->product_stock <= 0) || ($product->variant_product == 1))) {
                $pre_order_available = \Workdo\PreOrder\app\Models\PreOrderSetting::productStockAvailable($slug, $product->id);
                $latestSales = [];
            } else {
                $pre_order_available = [];
                $latestSales = Product::productSalesTag($store->slug, $product->id);
            }
        } else {
            $pre_order_available = [];
            $latestSales = Product::productSalesTag($store->slug, $product->id);
        }
        $productQuery = Product::where('store_id', $store->id)->where('product_type', null)->where('status', 1);

        $products = (clone $productQuery)->inRandomOrder()->limit(20)->get();
        return view('front_end.pages.product-detail', compact('slug', 'product', 'question', 'mi_price', 'ma_price', 'flashsales', 'latestSales', 'pre_order_available', 'products'));

    }

    public function cart_page(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        
        return view('front_end.pages.cart', compact('store'));
       
    }

    public function checkout(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        $payments = Setting::paymentList($slug);
        return view('front_end.pages.checkout', compact('store', 'payments'));
    }

    public function addressForm(Request $request, $slug)
    {
        $type = $request->type;
        $countries = Country::orderBy('name', 'ASC')->get();
        // if (isset($data['settings']['store_'.$type.'_location']) && $data['settings']['store_'.$type.'_location'] == 'except_countries') {
        //     $countries = Country::whereNotIn('id', json_decode($data['settings']['store_except_'.$type.'_country']))->orderBy('name', 'ASC')->get();
        // } elseif (isset($data['settings']['store_'.$type.'_location']) && $data['settings']['store_'.$type.'_location'] == 'specific_countries') {
        //     $countries = Country::whereIn('id', json_decode($data['settings']['store_'.$type.'_country']))->orderBy('name', 'ASC')->get();
        // }

        if (auth('customers')->user()) {
            $customer_address = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('default_address', 1)->first();

            if (empty($customer_address)) {
                $customer_address = null;
                $state_option = [];
                $city_option = [];
            } else {
                $state_option = State::where('country_id', $customer_address->country_id)->orderBy('name', 'ASC')->get();
                $city_option = City::where('state_id', $customer_address->state_id)->where('country_id', $customer_address->country_id)->orderBy('name', 'ASC')->get();
            }
        } else {
            $customer_address = null;
            $state_option = [];
            $city_option = [];
        }
        $data['html'] = view('front_end.pages.address-field', compact('type', 'countries', 'customer_address', 'state_option', 'city_option'))->render();

        return Utility::success($data , __('Form get successfully'));
        // return success('success', __('Form get successfully'), $data);
    }

    public function order_track(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
         
        if (!empty($request->order_number) || !empty($request->email)) { 
            $order = Order::where('product_order_id', $request->order_number)->where('store_id', $store->id)->first();
            if (!isset($order)) {
               return view('front_end.pages.order-status', compact('slug', 'store'));
            } else {
                $order_detail = Order::order_detail($order->id);
            }
            if (!empty($order)) {
                $customer = Customer::where('email', $order->email)->first();
            }

            $storeUrl = getFrontThemeUrl($store);
            $url =  $storeUrl .'/'. $slug . '/order/' . encrypt($order->id ?? '');
            return view('front_end.pages.order-status', compact('order', 'url', 'order_detail', 'customer', 'slug', 'store'));
        } else {
            return view('front_end.pages.order-status', compact('slug', 'store'));
        }
    }

    public function contactUs(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
       
        return view('front_end.pages.contact', compact('store'));
    }
    
    public function contactUsSave(Request $request, $slug)
    {
        try{
            $store = getStore($slug);
            if (!$store) {
                abort(404);
            }
            
            $request->validate([
                'first_name' => 'required',
                'email'   => 'required|email',
                'last_name'   => 'required',
                'subject'   => 'required',
                'description' => 'nullable',
            ]);

            $contact = Contact::create([
                'first_name'   => $request->first_name,
                'last_name'   => $request->last_name,
                'subject'   => $request->subject,
                'email'   => $request->email,
                'contact'   => $request->contact,
                'description' => $request->description,
                'store_id' => $store->id ?? null,
            ]);
            $contact->save();

            return back()->with('success', __('Thanks! Your message has been sent.'));
        }
        catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function search_products(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            $return['html_data'] = null;
            $return['message'] = __('Store not found.');

            return response()->json($return);
        }
       
        $search_pro = $request->product;

        $products = Product::where('name', 'LIKE', '%' . $search_pro . '%')->where('store_id', $store->id)->get();
       
        // Check if any matching products were found
        if (!$products->isEmpty()) {
            // Create an array of product URLs
            $productData = [];

            // Populate the array with product names and URLs
            foreach ($products as $product) {
                $url = url($store->slug . '/product/' . $product->slug);

                $productData[] = [
                    'name' => $product->name,
                    'url' => $url,
                ];
            }

            return response()->json($productData);
        } else {
             $url = url($store->slug . '/error');
             $productData[] = [
                    'name' => $request->product,
                    'url' => $url,
                ];
            // Handle the case where no matching products were found
            return response()->json($productData);
        }
    }

    public function privacy_page(Request $request, $slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back();
        }

        return view('front_end.pages.privacy-policy', compact('slug'));
    }

    public function SoftwareDetails($slug)
    {
        $modules_all = Module::all();
        $modules = [];
        if (count($modules_all) > 0) {
            // Ensure that array_rand() returns an array
            $randomKeys = (count($modules_all) === 1)
                ? [array_rand($modules_all)]  // Wrap single key in an array
                : array_rand($modules_all, (count($modules_all) < 6) ? count($modules_all) : 6);  // Get 6 or fewer random keys

            // Proceed with array_intersect_key
            $modules = array_intersect_key(
                $modules_all, // the array with all keys
                array_flip($randomKeys) // flip the random keys array
            );
        }
        $plan = Plan::first();

        $addon = AddOnManager::where('name', $slug)->first();

        if (!empty($addon) && !empty($addon->module)) {
            $module = Module::find($addon->module);
            if (!empty($module)) {
                try {
                    if (module_is_active('LandingPage')) {
                        return view('landing-page::marketplace.index', compact('modules', 'module', 'plan'));
                    } else {
                        return view($module->package_name . '::marketplace.index', compact('modules', 'module', 'plan'));
                    }
                } catch (\Throwable $th) {

                }
            }
        }
        if (module_is_active('LandingPage')) {
            $layout = 'landing-page::layouts.marketplace';

        } else {
            $layout = 'marketplace.marketplace';
        }
        return view('marketplace.detail_not_found', compact('modules', 'layout'));

    }

    public function Software(Request $request)
    {
        // Get the query parameter from the request
        $query = $request->query('query');
        // Get all modules (assuming Module::getByStatus(1) returns all modules)
        $modules = Module::getByStatus(1);

        // Filter modules based on the query parameter
        if ($query) {
            $modules = array_filter($modules, function ($module) use ($query) {
                // You may need to adjust this condition based on your requirements
                return stripos($module->name, $query) !== false;
            });
        }
        // Rest of your code
        if (module_is_active('LandingPage')) {
            $layout = 'landing-page::layouts.marketplace';
        } else {
            $layout = 'marketplace.marketplace';
        }

        return view('marketplace.software', compact('modules', 'layout'));
    }

    public function Pricing()
    {
        $admin_settings = getAdminAllSetting();
        if (module_is_active('GoogleCaptcha') && (isset($admin_settings['google_recaptcha_is_on']) ? $admin_settings['google_recaptcha_is_on'] : 'off') == 'on') {
            config(['captcha.secret' => isset($admin_settings['google_recaptcha_secret']) ? $admin_settings['google_recaptcha_secret'] : '']);
            config(['captcha.sitekey' => isset($admin_settings['google_recaptcha_key']) ? $admin_settings['google_recaptcha_key'] : '']);
        }
        if (auth()->check()) {
            if (auth()->user()->type == 'admin') {
                return redirect('plans');
            } else {
                return redirect('dashboard');
            }
        } else {
            $plan = Plan::first();
            $modules = Module::getByStatus(1);

            if (module_is_active('LandingPage')) {
                $layout = 'landing-page::layouts.marketplace';

                return view('landing-page::layouts.pricing', compact('modules', 'plan', 'layout'));

            } else {
                $layout = 'marketplace.marketplace';
            }

            return view('marketplace.pricing', compact('modules', 'plan', 'layout'));
        }
    }

    public function top_brand_category_chart(Request $request)
    {
        $tab_name = $request->tabId;
        $type = $request->type;
        if ($type == 'category') {
            if ($tab_name == '#all-category-order') {
                $top_sales = Category::select('categories.name as sale_name', 'categories.image_path as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'categories.id', '=', 'products.category_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'));
                    })
                    ->where('categories.store_id', getCurrentStore())
                    ->groupBy('categories.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();

            } elseif ($tab_name == '#today-category-order') {

                $top_sales = Category::select('categories.name as sale_name', 'categories.image_path as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'categories.id', '=', 'products.category_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereDate('orders.created_at', '=', \DB::raw('CURDATE()'));
                    })
                    ->where('categories.store_id', getCurrentStore())
                    ->groupBy('categories.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();

            } elseif ($tab_name == '#week-category-order') {
                $top_sales = Category::select('categories.name as sale_name', 'categories.image_path as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'categories.id', '=', 'products.category_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereBetween('orders.created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                    })
                    ->where('categories.store_id', getCurrentStore())
                    ->groupBy('categories.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();

            } elseif ($tab_name == '#month-category-order') {
                $top_sales = Category::select('categories.name as sale_name', 'categories.image_path as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'categories.id', '=', 'products.category_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereYear('orders.created_at', now()->year)
                            ->whereMonth('orders.created_at', now()->month);
                    })
                    ->where('categories.store_id', getCurrentStore())
                    ->groupBy('categories.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            } elseif ($tab_name == '#year-category-order') {
                $top_sales = Category::select('categories.name as sale_name', 'categories.image_path as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'categories.id', '=', 'products.category_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereYear('orders.created_at', now()->year);
                    })
                    ->where('categories.store_id', getCurrentStore())
                    ->groupBy('categories.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            } else {
                $top_sales = Category::select('categories.name as sale_name', 'categories.image_path as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'categories.id', '=', 'products.category_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'));
                    })
                    ->where('categories.store_id', getCurrentStore())
                    ->groupBy('categories.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            }
        } else {
            if ($tab_name == '#all-brand-order') {
                $top_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'product_brands.id', '=', 'products.brand_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'));
                    })
                    ->where('product_brands.store_id', getCurrentStore())
                    ->groupBy('product_brands.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            } elseif ($tab_name == '#today-brand-order') {

                $top_sales = ProductBrand::select('product_brands.name as sale_name', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'product_brands.id', '=', 'products.brand_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereDate('orders.created_at', '=', \DB::raw('CURDATE()'));
                    })
                    ->where('product_brands.store_id', getCurrentStore())
                    ->groupBy('product_brands.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();

            } elseif ($tab_name == '#week-brand-order') {
                $top_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'product_brands.id', '=', 'products.brand_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereBetween('orders.created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                    })
                    ->where('product_brands.store_id', getCurrentStore())
                    ->groupBy('product_brands.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            } elseif ($tab_name == '#month-brand-order') {
                $top_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'product_brands.id', '=', 'products.brand_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereYear('orders.created_at', now()->year)
                            ->whereMonth('orders.created_at', now()->month);
                    })
                    ->where('product_brands.store_id', getCurrentStore())
                    ->groupBy('product_brands.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            } elseif ($tab_name == '#year-brand-order') {
                $top_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'product_brands.id', '=', 'products.brand_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'))
                            ->whereYear('orders.created_at', now()->year);
                    })
                    ->where('product_brands.store_id', getCurrentStore())
                    ->groupBy('product_brands.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            } else {
                $top_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('SUM(orders.final_price) as total_sale'))
                    ->join('products', 'product_brands.id', '=', 'products.brand_id')
                    ->join('orders', function ($join) {
                        $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                            ->crossJoin(\DB::raw('(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers'));
                    })
                    ->where('product_brands.store_id', getCurrentStore())
                    ->groupBy('product_brands.name')
                    ->orderBy('total_sale', 'desc')
                    ->get();
            }
        }
        $html = '';
        $html = view('order.brand_category_chart', compact('tab_name', 'top_sales'))->render();

        $return['html'] = $html;
        $return['tab_name'] = $tab_name;
        $return['type'] = $type;

        return response()->json($return);
    }

    public function best_selling_brand_chart(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $currency = Utility::GetValueByName('defult_currancy_symbol', $store_id->id);
        if (empty($currency)) {
            $currency = '$';
        }
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            $lastMonth = Carbon::now()->subMonth();
            $prevMonth = strtotime('-1 month');
            $start = strtotime(date('Y-m-01', $prevMonth));
            $end = strtotime(date('Y-m-t', $prevMonth));

            $customer = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', null)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';

            $lastDayofMonth = Carbon::now()->subMonthNoOverflow()->endOfMonth();
            $lastday = date('j', strtotime($lastDayofMonth));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE)); // Extract day of the month

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }

            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE));
                $userTotalArray[$day][] = $order->order_date;

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            $lastDayofMonth = \Carbon\Carbon::now()->endOfMonth()->toDateString();
            $lastday = date('j', strtotime($lastDayofMonth));

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $startDate = now()->subDays(6);

            $TotalOrder = 0;
            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            $monthList = [];
            $previous_week = strtotime('-1 week +1 day');

            for ($i = 0; $i <= 7 - 1; $i++) {
                $date = date('Y-m-d', $previous_week);
                $previous_week = strtotime(date('Y-m-d', $previous_week) . ' +1 day');
                $monthList[] = __(date('d-M', strtotime($date)));

                $ordersForDate = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get();
                $TotalOrder += $ordersForDate->count();
                $totalPurchasedItemsForDate = 0;

                foreach ($ordersForDate as $order) {
                    $products = json_decode($order->product_json, true);

                    $totalProductQuantity = array_reduce($products, function ($carry, $product) {
                        return $carry + intval($product['qty']);
                    }, 0);
                    $totalPurchasedItemsForDate += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $PurchasedItemTotal[] = $totalPurchasedItemsForDate;

                $totalOrdersForDate = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->count();

                $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');

                $NetSaleTotal[] = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $CouponTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $ShippingTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;

                $averageGrossSales[] = $totalOrdersForDate > 0 ? ($GrossSaleTotal[count($GrossSaleTotal) - 1] / $totalOrdersForDate) : 0;
                $averageNetSales[] = $totalOrdersForDate > 0 ? ($NetSaleTotal[count($NetSaleTotal) - 1] / $totalOrdersForDate) : 0;

                $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
                $TotalNetSale += Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $TotalCouponAmount += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $TotalShippingCharge += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;
            }
        } elseif ($request->chart_data == 'year') {

            $TotalOrder = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();

            $orders = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $order = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', date('Y'))
                ->get()->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $netSaleTotalArray[$order->month][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$order->month][] = (float) $order->final_price;
                $CouponTotalArray[$order->month][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$order->month][] = (float) $order->delivery_price;
                $OrderTotalArray[$order->month][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$order->month][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            for ($i = 1; $i <= 12; $i++) {

                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $monthlySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $average = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageGrossSales[] = $average;

                $monthlySales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $netsaleaverage = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageNetSales[] = $netsaleaverage;
            }
            $monthList = $month = $this->yearMonth();
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $form_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $start_date = date('Y-m-d', strtotime($date_range[0]));
                    $end_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {

                $form_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }
            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            $monthLists = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $monthLists = Order::whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $monthLists = $monthLists->get();

            foreach ($monthLists as $monthLists_date) {
                $data[] = date('y-n-j', strtotime($monthLists_date->order_date));
                $data_month[] = date('Y-m-d', strtotime($monthLists_date->order_date));
            }
            if (!empty($data) && is_array($data)) {
                $List = array_values(array_unique($data));
                $monthList_data = $List;
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            } else {
                $List = [];
                $monthList_data = [];
                $data_month[] = date('y-n-j');
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            }

            foreach ($orders as $order) {
                $day = date('y-n-j', strtotime($order->DATE));
                $userTotalArray[$day][] = date('y-n-j', strtotime($order->order_date));

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            if (!empty($data) && is_array($data)) {
                foreach ($monthList_data as $month) {
                    $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                    $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                    $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                    $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;
                    $TotalOrderCount[] = array_key_exists($month, $OrderTotalArray) ? count($OrderTotalArray[$month]) : 0;

                    $PurchasedItemTotal[] = array_key_exists($month, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$month]) : 0;

                    $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                    $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                    $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                    $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
                }
            } else {
                $month = date('y-n-j');
                $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;
                $TotalOrderCount[] = array_key_exists($month, $OrderTotalArray) ? count($OrderTotalArray[$month]) : 0;

                $PurchasedItemTotal[] = array_key_exists($month, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$month]) : 0;

                $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
        }

        $html = '';
        $html = view('reports.order_chart_data', compact('TotalOrder', 'PurchasedProductItemTotal', 'TotalgrossSale', 'currency', 'TotalNetSale', 'TotalCouponAmount', 'TotalShippingCharge'))->render();

        $return['html'] = $html;

        $return['TotalOrderCount'] = $TotalOrderCount;
        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['AverageNetSales'] = $averageNetSales;
        $return['GrossSaleTotal'] = $GrossSaleTotal;
        $return['AverageGrossSales'] = $averageGrossSales;
        $return['PurchasedItemTotal'] = $PurchasedItemTotal;
        $return['ShippingTotal'] = $ShippingTotal;
        $return['CouponTotal'] = $CouponTotal;
        $return['monthList'] = $monthList;
        Session::put('order_line_chart_report', $return);

        return response()->json($return);
    }

    public function pageError(Request $request, $slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->back();
        }

        return view('front_end.pages.error', compact('store'));
    }

    public function wishlist(Request $request)
    {
        // if (! auth('customers')->user()) {
        //     return redirect()->back()->with('error', __('Permission denied'));
        // }
        // $wishlists = Wishlist::with('ProductData')->where('customer_id', auth('customers')->id())->get();
        $wishlists = Wishlist::with('ProductData')->get();
        return view('front_end.pages.wishlist', compact('wishlists'));
    }

    public function showCategory(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
       
        $categories = Category::where('status', 1)->where('store_id', $store->id)->get();
        $topCategories = Category::select('categories.id', 'categories.slug', 'categories.image_path', 'categories.name', DB::raw('COUNT(orders.id) as total_sales'))
        ->leftjoin('products', 'categories.id', '=', 'products.category_id')
        ->leftjoin('orders', 'products.id', '=', 'orders.product_id')
        ->where('categories.store_id', $store->id)
        ->where('categories.status', 1)
        ->groupBy('categories.id', 'categories.name')
        ->orderBy('total_sales', 'DESC')
        ->limit(2)
        ->get();
        return view('front_end.pages.collection-list', compact('store', 'categories', 'topCategories'));
    }

    public function orderTrack(Request $request, $slug)
    {
        $store = getStore($request->route('storeSlug'));
        if (!$store) {
            abort(404);
        }        
        return view('front_end.pages.track-order', compact('store'));
    }

    public function customPage(Request $request)
    {
        $store = getStore($request->route('storeSlug'));
        if ($store && $request->route('page_slug')) {
            $page   = Page::where('page_slug', $request->route('page_slug'))->where('page_status', 1)->first();
            if ($page) {
                $slug = $store->slug;               
                return view('front_end.pages.page', compact('page', 'store'));
            } else {
                abort(404);
            }
        }
    }
}
