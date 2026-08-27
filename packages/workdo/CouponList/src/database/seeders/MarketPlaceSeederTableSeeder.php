<?php

namespace workdo\CouponList\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Workdo\LandingPage\Entities\MarketplacePageSetting;
class MarketPlaceSeederTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Model::unguard();

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Coupon List';
        $data['product_main_description'] = '<p>Our Coupon List Module simplifies the process of managing discounts for your online store. With clear and organized listings, you can effortlessly keep track of all active promotions, their respective discount amounts, and expiration dates. This streamlines your marketing efforts, ensuring that you can easily monitor and update your discount strategy to maximize savings for both your customers and your business.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Strategic Promotion Planning';
        $data['dedicated_theme_description'] = '<p>The Coupon List Module empowers you to strategically plan your promotional campaigns. By knowing the validity periods of each coupon, you can schedule promotions to coincide with peak shopping times or align them with specific marketing initiatives.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Boosted Sales and Conversion Rates","dedicated_theme_section_description":"Coupons are powerful tools for driving sales and increasing conversion rates. With enticing discounts readily available through our Coupon List Module, you can attract more customers and encourage them to complete their purchases. Whether its a percentage off the total order price or a fixed amount discount, these offers incentivize shoppers to buy, resulting in boosted sales and revenue for your eCommerce business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Flexibility and Customization","dedicated_theme_section_description":"Our Coupon List Module offers flexibility and customization options to suit your unique business needs. You can easily create, edit, and deactivate coupons as needed, allowing you to adapt your discount strategy in response to changing market conditions or promotional goals. This flexibility enables you to stay agile in your marketing efforts and continually optimize your discount offerings for maximum effectiveness."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Coupon List"},{"screenshots":"","screenshots_heading":"Coupon List"},{"screenshots":"","screenshots_heading":"Coupon List"},{"screenshots":"","screenshots_heading":"Coupon List"},{  "screenshots":"","screenshots_heading":"Coupon List"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'CouponList')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'CouponList'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
