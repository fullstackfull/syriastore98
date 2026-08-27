<?php

namespace Workdo\ProductPricing\Database\Seeders;

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
        $module = 'ProductPricing';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'ProductPricing';
        $data['product_main_description'] = '<p>Enhance your pricing strategy with the Product Pricing Module is conditional pricing capabilities. This feature allows you to set specific rules to increase or decrease prices based on predefined conditions. Whether offering discounts during sales periods or adjusting prices according to market trends, the flexibility provided by conditional pricing ensures that your pricing strategy is always aligned with your business goals.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Customizable Pricing Rules';
        $data['dedicated_theme_description'] = '<p>The module empowers you to create and manage various pricing conditions tailored to your needs. You can define rules based on factors such as purchase quantity, customer segments, promotional events, and inventory levels. This customization ensures that your pricing strategy is as dynamic and responsive as your business, allowing you to optimize prices for maximum profitability and customer satisfaction.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Real-Time Price Updates","dedicated_theme_section_description":"With real-time updates, any changes made to product prices based on the conditions set are immediately reflected in your store. Customers will always see the most accurate and relevant prices, enhancing transparency and trust. This feature not only improves the shopping experience but also ensures that your pricing strategy is responsive to real-time market changes."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"User-Friendly Management","dedicated_theme_section_description":"The Product Pricing Module is designed with a user-friendly interface that simplifies the process of setting up and managing pricing conditions. You do not need technical expertise to utilize this powerful tool effectively. The intuitive design allows you to focus on strategic pricing decisions, making it easy to implement complex pricing strategies with just a few clicks."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ProductPricing"},{"screenshots":"","screenshots_heading":"ProductPricing"},{"screenshots":"","screenshots_heading":"ProductPricing"},{"screenshots":"","screenshots_heading":"ProductPricing"},{  "screenshots":"","screenshots_heading":"ProductPricing"}]';
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
