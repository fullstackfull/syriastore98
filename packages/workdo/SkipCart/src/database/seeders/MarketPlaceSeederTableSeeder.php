<?php

namespace Workdo\SkipCart\Database\Seeders;

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
        $module = 'SkipCart';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'SkipCart';
        $data['product_main_description'] = '<p>The Skip Cart module in eCommerceGo SaaS is designed to enhance the shopping experience by streamlining the checkout process. This module offers a more efficient way for customers to complete their purchases, reducing friction and improving overall satisfaction. By enabling this module, you can allow your customers to skip unnecessary steps and proceed directly to payment, making the shopping process faster and more convenient.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Single Tap Checkout';
        $data['dedicated_theme_description'] = '<p>The first feature of the Skip Cart module is the Single Tap Checkout. When this setting is enabled from the backend, your customers can choose their preferred checkout path. If they select the "checkout page" option, they will be directly navigated to the checkout page after selecting an item. Alternatively, if they choose the "cart page" option, they will be directed to the cart page first. This flexibility allows customers to streamline their shopping experience based on their preferences, enhancing user satisfaction and potentially increasing conversion rates.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"On-Page Checkout","dedicated_theme_section_description":"The second page of the Skip Cart module focuses on On-Page Checkout. With this feature, when a product is added to the cart, the customer is immediately taken to the checkout page. This eliminates the need for multiple steps and pages, making the purchase process quicker and more straightforward. By minimizing the number of clicks required to complete a purchase, the On-Page Checkout feature can significantly reduce cart abandonment rates and boost sales."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Sticky Cart Checkout","dedicated_theme_section_description":"The Skip Cart module also includes a Sticky Cart function, which enhances the shopping experience by keeping the cart visible and easily accessible at all times. Customers can adjust the position of the sticky cart to suit their browsing preferences, ensuring that it does not obstruct their view of the products. This constant visibility allows customers to review their selections at any moment, making it easier to add or remove items without having to navigate away from the product pages. The sticky cart function helps to create a more seamless and user-friendly shopping experience."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SkipCart"},{"screenshots":"","screenshots_heading":"SkipCart"},{"screenshots":"","screenshots_heading":"SkipCart"},{"screenshots":"","screenshots_heading":"SkipCart"},{"screenshots":"","screenshots_heading":"SkipCart"}]';
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
