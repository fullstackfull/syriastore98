<?php

namespace Workdo\StoreLocator\Database\Seeders;

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
        $module = 'StoreLocator';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Store Locator';
        $data['product_main_description'] = '<p>The Store Locator Module of eCommerceGo SaaS elevates the customer experience by providing an intuitive and convenient way for shoppers to find physical store locations. With this module, businesses can bridge the gap between online and offline shopping, empowering customers to easily locate nearby stores and make informed purchase decisions.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhanced Customer Experience';
        $data['dedicated_theme_description'] = '<p>The Store Locator Module of eCommerceGo SaaS enables customers to easily find and navigate to physical store locations, enhancing convenience and driving foot traffic for businesses.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration with Online Stores","dedicated_theme_section_description":"Integrating seamlessly with online stores, the Store Locator Module allows businesses to display store locations directly on their e-commerce websites. Customers can access the store locator tool from any device, including desktops, tablets, and smartphones, ensuring accessibility and convenience. By offering this feature, businesses can enhance the overall shopping experience and drive foot traffic to their physical locations."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Interactive Mapping Functionality","dedicated_theme_section_description":"The Store Locator Module offers interactive mapping functionality, enabling customers to visually explore store locations and surrounding areas. Through dynamic maps, customers can view detailed directions, check store hours, and discover nearby amenities such as parking facilities and public transportation options. This interactive approach enhances engagement and provides customers with valuable information to plan their shopping trips effectively."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"StoreLocator"},{"screenshots":"","screenshots_heading":"StoreLocator"},{"screenshots":"","screenshots_heading":"StoreLocator"},{"screenshots":"","screenshots_heading":"StoreLocator"},{"screenshots":"","screenshots_heading":"StoreLocator"}]';
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
