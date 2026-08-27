<?php

namespace Workdo\FreeShippingPopup\database\seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
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
        $data['product_main_heading'] = 'Free Shipping Pop-Up';
        $data['product_main_description'] = '<p>EcommerceGo SaaS is proud to introduce our new Free Shipping Pop-Up Module, a powerful tool designed to enhance the shopping experience for your customers while boosting your sales. This innovative feature automatically displays a pop-up notification offering free shipping to eligible customers, encouraging them to complete their purchases. With this module, you can effortlessly highlight one of the most attractive incentives for online shoppers, helping to reduce cart abandonment and increase conversion rates.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhance Customer Experience';
        $data['dedicated_theme_description'] = '<p>Free shipping is a powerful motivator for online shoppers, and our pop-up module ensures that this incentive is prominently displayed to your customers at the right moment.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration and Customization","dedicated_theme_section_description":"Integrating the Free Shipping Pop-Up Module into your EcommerceGo platform is a breeze. The module is designed to work seamlessly with your existing setup, requiring minimal configuration. You can easily customize the appearance and messaging of the pop-up to align with your brands look and feel. Tailor the criteria for free shipping eligibility, such as minimum order value or specific product categories, to meet your business needs. This flexibility ensures that your free shipping offer is both appealing to customers and beneficial for your bottom line."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Boost Sales and Reduce Cart Abandonment","dedicated_theme_section_description":"Cart abandonment is a common challenge for online retailers, often caused by unexpected shipping costs. The Free Shipping Pop-Up Module addresses this issue head-on by highlighting the availability of free shipping early in the shopping process. When customers know they can save on shipping costs, they are more likely to proceed to checkout. By reducing one of the main barriers to purchase, this module helps you capture more sales and decrease the number of abandoned carts on your site."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Free Shipping Pop-Up"},{"screenshots":"","screenshots_heading":"Free Shipping Pop-Up"},{"screenshots":"","screenshots_heading":"Free Shipping Pop-Up"},{"screenshots":"","screenshots_heading":"Free Shipping Pop-Up"},{  "screenshots":"","screenshots_heading":"Free Shipping Pop-Up"}]';
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

        foreach ($data as $key => $value) {
            if (!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'FreeShippingPopup')->exists()) {
                MarketplacePageSetting::updateOrCreate(
                    [
                        'name' => $key,
                        'module' => 'FreeShippingPopup'

                    ],
                    [
                        'value' => $value
                    ]
                );
            }
        }
    }
}
