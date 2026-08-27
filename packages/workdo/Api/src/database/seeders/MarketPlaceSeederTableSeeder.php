<?php

namespace Workdo\Api\Database\Seeders;

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
        $module = 'Api';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'API';
        $data['product_main_description'] = '<p>The eCommerceGo API module empowers developers and businesses to extend and customize the functionality of their ecommerce platform. By leveraging our robust and scalable API, you can seamlessly integrate with third-party applications, streamline operations, and create a more tailored shopping experience for your customers.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Robust and Scalable Solutions';
        $data['dedicated_theme_description'] = '<p>Effortlessly integrate and extend your ecommerce capabilities with EcommerceGo robust and scalable API module.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Data Synchronization","dedicated_theme_section_description":"With the eCommerceGo API, you can ensure real-time synchronization of data across all your platforms. Whether its updating product inventories, tracking orders, or managing customer information, our API facilitates instant data exchange, keeping everything up-to-date and accurate, which is critical for effective decision-making and customer satisfaction."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Extensive Documentation and Support","dedicated_theme_section_description":"The eCommerceGo API comes with comprehensive documentation and robust support to assist developers at every step. Our detailed guides, code samples, and responsive support team make it easy to implement and troubleshoot integrations, ensuring you can leverage the full potential of our API with minimal hassle."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"API"},{"screenshots":"","screenshots_heading":"API"},{"screenshots":"","screenshots_heading":"API"},{"screenshots":"","screenshots_heading":"API"},{  "screenshots":"","screenshots_heading":"API"}]';
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
