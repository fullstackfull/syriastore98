<?php

namespace Workdo\WholesaleProduct\Database\Seeders;

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

        // $this->call("OthersTableSeeder");

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'WholesaleProduct';
        $data['product_main_description'] = '<p>The Wholesale Module of eCommerceGo SaaS offers a seamless solution for managing bulk orders and wholesale pricing. This powerful tool simplifies the process of adding multiple products with their respective wholesale prices, providing flexibility and efficiency in wholesale operations.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Streamlined Product Management';
        $data['dedicated_theme_description'] = '<p>Adding products with wholesale pricing is incredibly straightforward with our intuitive interface. You can effortlessly input details for each product along with its wholesale price. Whether you are selling electronics, clothing, or accessories, our module makes it easy to manage your wholesale product catalog with precision.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Flexible Wholesale Pricing","dedicated_theme_section_description":"Set wholesale prices to cater to your bulk orders and wholesale clients. Our module offers flexibility in pricing, allowing you to provide competitive rates while ensuring profitability. Whether you prefer tiered pricing based on quantity or fixed wholesale rates, our system can adapt to your pricing strategy seamlessly."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Order Processing","dedicated_theme_section_description":"Processing wholesale orders becomes a breeze with our module. Wholesale customers can conveniently place bulk orders through your eCommerce storefront, selecting the quantities they require at the wholesale prices you have set. This streamlined ordering process enhances the buying experience for your wholesale clients and simplifies order fulfillment on your end."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Comprehensive Reporting and Management","dedicated_theme_section_description":"<p>Track your wholesale sales and performance effortlessly with comprehensive reporting features. Our module provides detailed insights into wholesale orders, enabling you to monitor sales trends, track inventory levels, and analyze customer behavior. Armed with this valuable data, you can make informed decisions to optimize your wholesale operations and drive business growth.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"WholesaleProduct"},{"screenshots":"","screenshots_heading":"WholesaleProduct"},{"screenshots":"","screenshots_heading":"WholesaleProduct"},{"screenshots":"","screenshots_heading":"WholesaleProduct"},{"screenshots":"","screenshots_heading":"WholesaleProduct"}]';
        $data['addon_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['addon_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['addon_section_status'] = 'on';
        $data['whychoose_heading'] = 'Why choose dedicated modulesfor Your Business?';
        $data['whychoose_description'] = '<p>With EcommerceGo, you can conveniently manage all your business functions from a single location.</p>';
        $data['pricing_plan_heading'] = 'Empower Your Workforce with EcommerceGo';
        $data['pricing_plan_description'] = '<p>Access over Premium Add-ons for Product Enquiry, Product Review, Product Quick-view, and more, all in one place!</p>';
        $data['pricing_plan_demo_link'] = '#';
        $data['pricing_plan_demo_button_text'] = 'View Live Demo';
        $data['pricing_plan_text'] = '{"1":{"title":"Pay-as-you-go"},"2":{"title":"Unlimited installation"},"3":{"title":"Secure cloud storage"}}';
        $data['whychoose_sections_status'] = 'on';
        $data['dedicated_theme_section_status'] = 'on';

        foreach($data as $key => $value){
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'WholesaleProduct')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'WholesaleProduct'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
