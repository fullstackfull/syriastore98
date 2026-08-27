<?php

namespace Workdo\QuickCheckout\Database\Seeders;

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
        $module = 'QuickCheckout';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Quick Checkout';
        $data['product_main_description'] = '<p>The QuickCheckOut module of eCommerceGo revolutionizes the way customers complete their purchases. Designed with user convenience in mind, it minimizes the steps required to finalize a transaction. By reducing the number of clicks and form fields, QuickCheckOut ensures a smooth, efficient process that encourages customers to complete their purchases without hesitation. This streamlined approach not only improves the customer experience but also reduces cart abandonment rates, leading to higher conversion rates and increased sales.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Streamlined Purchasing Experience';
        $data['dedicated_theme_description'] = '<p>The QuickCheckout module of eCommerceGo SaaS streamlines the checkout process, offering customers a swift and hassle-free experience, ultimately boosting conversions and sales.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Secure Payment Gateway","dedicated_theme_section_description":"Security is a top priority for eCommerceGo, and the QuickCheckOut module reflects this commitment. Integrated with advanced encryption technologies and secure payment gateways, it ensures that all transactions are protected against fraud and unauthorized access. Customers can confidently enter their payment details, knowing that their sensitive information is safe. By providing a secure checkout environment, eCommerceGo helps build trust and loyalty among customers, encouraging repeat business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"User-Friendly Interface","dedicated_theme_section_description":"The QuickCheckOut module features an intuitive and user-friendly interface that caters to all types of customers, whether they are tech-savvy or less experienced with online shopping. The interface is designed to be clean and clutter-free, with clear instructions and easily navigable steps. This simplicity helps reduce confusion and frustration, allowing customers to quickly and effortlessly complete their purchases. The user-friendly design ensures that the checkout process is accessible and enjoyable for everyone."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Quick Checkout"},{"screenshots":"","screenshots_heading":"Quick Checkout"},{"screenshots":"","screenshots_heading":"Quick Checkout"},{"screenshots":"","screenshots_heading":"Quick Checkout"},{  "screenshots":"","screenshots_heading":"Quick Checkout"}]';
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
