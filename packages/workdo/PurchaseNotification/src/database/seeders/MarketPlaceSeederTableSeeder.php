<?php

namespace Workdo\PurchaseNotification\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\LandingPage\Entities\MarketplacePageSetting;


class MarketPlaceSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        $module = 'PurchaseNotification';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Purchase Notification';
        $data['product_main_description'] = '<p>Simplify your customer communication process with eCommerceGo SaaSs purchase notification module. Our platform automates the process of sending order updates to your customers, ensuring they are always in the know. Saving you time and effort.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Efficiency and Accuracy';
        $data['dedicated_theme_description'] = '<p>eCommerceGo SaaS purchase notification module synchronizes order data in real-time, reducing the risk of errors and ensuring accuracy. Seamlessly integrate with your existing order management system for maximum efficiency. Whether you are processing orders through your eCommerce platform or third-party channels, our module ensures that purchase notifications are sent promptly and accurately.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Status Updates","dedicated_theme_section_description":"Keep your customers informed every step of the way with real-time order updates from eCommerceGo SaaS. Whether its confirming an order, processing it, or providing tracking information, our module ensures your customers receive timely notifications. Say goodbye to manual notifications and hello to automated efficiency with eCommerceGo SaaS."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Customer Experience","dedicated_theme_section_description":"By providing automated order updates, eCommerceGo SaaS helps enhance the overall customer experience. Keep your customers informed, satisfied, and coming back for more with timely and accurate notifications. With eCommerceGo SaaS, you can streamline your communication process and delight your customers with exceptional service."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"PurchaseNotification"},{"screenshots":"","screenshots_heading":"PurchaseNotification"},{"screenshots":"","screenshots_heading":"PurchaseNotification"},{"screenshots":"","screenshots_heading":"PurchaseNotification"},{"screenshots":"","screenshots_heading":"PurchaseNotification"}]';
        $data['addon_heading'] = 'Why choose dedicated modules for Your Business?';
        $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modules for Your Business?';
        $data['whychoose_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['pricing_plan_heading'] = 'Empower Your Workforce with EcommerceGo';
        $data['pricing_plan_description'] = '<p>Access over Premium Add-ons for Product Enquiry, Product Review, Product Quick-view, and more, all in one place!</p>';
        $data['pricing_plan_demo_link'] = '#';
        $data['pricing_plan_demo_button_text'] = 'View Live Demo';
        $data['pricing_plan_text'] = '{"1":{"title":"Pay-as-you-go"},"2":{"title":"Unlimited installation"},"3":{"title":"Secure cloud storage"}}';
        $data['whychoose_sections_status'] = 'on';
        $data['dedicated_theme_section_status'] = 'on';

        foreach($data as $key => $value){
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', $module)->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => $module

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
