<?php
namespace Workdo\BulkDelete\app\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\PlanCoupon;
use App\Models\ProductBrand;
use App\Models\Cart;
use App\Models\City;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Faq;
use App\Models\FlashSale;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Newsletter;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Page;
use App\Models\PlanUserCoupon;
use App\Models\PlanOrder;
use App\Models\PlanRequest;
use App\Models\ProductAttribute;
use App\Models\Product;
use App\Models\ProductLabel;
use App\Models\ProductQuestion;
use App\Models\Role;
use App\Models\Shipping;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\State;
use App\Models\SupportTicket;
use App\Models\Tag;
use App\Models\Tax;
use App\Models\TaxMethod;
use App\Models\Testimonial;
use App\Models\UserCoupon;
use App\Models\Wishlist;

class BulkDeleteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('bulk-delete::pages.index');
    }


    /**
     * Store a newly created resource in storage.
     */
    public function bulkDelete(Request $request)
    {
        // Define a mapping of request types to models
        $modelMap = [
            'blog' => Blog::class,
            'blog-category' => BlogCategory::class,
            'plan-coupon' => PlanCoupon::class,
            'product-brand' => ProductBrand::class,
            'abandoncart'=> Cart::class,
            'contact' => Contact::class,
            'coupon' => Coupon::class,
            'currency' => Currency::class,
            'customer' => Customer::class,
            'faqs' => Faq::class,
            'flash-sale' => FlashSale::class,
            'main-category' => Category::class,
            'menu-table' => Menu::class,
            'newsletter' => Newsletter::class,
            'order' => Order::class,
            'order-refunds' => OrderRefund::class,
            'page' => Page::class,
            'plan-coupon-detail' => PlanUserCoupon::class,
            'plan-order' => PlanOrder::class,
            'plan-request' => PlanRequest::class,
            'product-attribute' => ProductAttribute::class,
            'Product' => Product::class,
            'product-label' => ProductLabel::class,
            'product-question' => ProductQuestion::class,
            'roles' => Role::class,
            'sales-downloadable-product' => Order::class,
            'shipping' => Shipping::class,
            'shipping-taxmethod' => ShippingMethod::class,
            'shipping-zone' => ShippingZone::class,
            'supportticket' => SupportTicket::class,
            'tag' => Tag::class,
            'taxes'=> Tax::class,
            'tax-method' => TaxMethod::class,
            'testimonial'=> Testimonial::class,
            'user-coupon' => UserCoupon::class,
            'wishlist' => Wishlist::class,
            'auction-product-addon' => Product::class,
            'digital-product-addon' => Product::class,
            'product-barcode-addon' => Product::class,
            'country' => Country::class,
            'state' => State::class,
            'city' => City::class,      
        ];

        // Conditionally load Workdo models
        $this->loadConditionalModels($modelMap);
       
        // Check if the request type exists in the model map
        if (array_key_exists($request->type, $modelMap)) {
            $model = $modelMap[$request->type];
            if (in_array($request->type, ['abandoncart', 'wishlist'])) {
                $model::whereIn('customer_id', $request->ids)->delete();
            } else {
                $model::whereIn('id', $request->ids)->delete();
            }
            return response()->json(['status' => true, 'message' => __("Records deleted successfully")]);
        }

        // Handle the case where the type is invalid
        return response()->json(['status' => false, 'message' => __("Invalid type specified")], 400);
    }

    /**
     * Dynamically load Workdo models if they exist.
     *
     * @param array &$modelMap The model map to extend
     * @return void
     */
    private function loadConditionalModels(array &$modelMap)
    {
        $conditionalModels = [
            'additionalfield' => 'Workdo\AdditionalFieldInCheckout\app\Models\AdditionalField',
            'auctionproductorder' => 'Workdo\AuctionProduct\app\Models\AuctionProductOrder',
            'auctionproduct-setting' => 'Workdo\AuctionProduct\app\Models\AuctionProductSetting',
            'backuprestorelog' => 'Workdo\BackupRestore\app\Models\BackupRestoreLog',
            'bundleproduct' => 'Workdo\BundleProduct\app\Models\BundleProduct',
            'campaigns' => 'Workdo\Campaigns\app\Models\Campaigns',
            'customerdonation' => 'Workdo\Donation\app\Models\CustomerDonation',
            'donation' => 'Workdo\Donation\app\Models\Donation',
            'helpdeskticketcategory' => 'Workdo\Helpdesk\app\Models\HelpdeskCategory',
            'helpdeskticket' => 'Workdo\Helpdesk\app\Models\HelpdeskTicket',
            'helpdeskticket-status' => 'Workdo\Helpdesk\app\Models\HelpdeskStatus',
            'marketing-appointment' => 'Workdo\Marketing\app\Models\AppointmentDeatail',
            'marketing-contact' => 'Workdo\Marketing\app\Models\MarketingContact',
            'orderpartialpayment' => 'Workdo\PartialPayments\app\Models\OrderPartialPayments',
            'pdfVoucher-addon' => 'Workdo\PDFVouchers\app\Models\CouponRequest',
            'productcatelog' => 'Workdo\ProductCatelog\app\Models\ProductCatelog',
            'productenquiry' => 'Workdo\ProductEnquiry\app\Models\ProductEnquiry',
            'product-review' => 'Workdo\ProductReview\app\Models\ProductReview',
            'recentlyviewedproduct' => 'Workdo\RecentlyViewedProducts\app\Models\RecentlyViewedProduct',
            'order-reviews' => 'Workdo\ReviewReminder\app\Models\OrderReviews',
            'productcomment' => 'Workdo\SpamPrevent\app\Models\ProductComment',
            'countdown-addon' => 'Workdo\CountDownTimer\app\Models\CountDownTimer',
            'productpricing-addon' => 'Workdo\ProductPricing\app\Models\ProductPricingRule',
        ];

        foreach ($conditionalModels as $key => $class) {
            if (class_exists($class)) {
                $modelMap[$key] = $class;
            }
        }
    }
}
