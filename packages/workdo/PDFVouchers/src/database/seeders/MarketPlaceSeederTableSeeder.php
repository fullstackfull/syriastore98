<?php

namespace Workdo\PDFVouchers\Database\Seeders;

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
        $data['product_main_heading'] = 'PDF Vouchers';
        $data['product_main_description'] = '<p>EcommerceGo SaaS introduces a versatile PDF Voucher Module designed to streamline the distribution of coupon codes. This feature empowers merchants to manage and distribute promotional coupons efficiently, enhancing their marketing efforts and customer engagement. By leveraging this module, businesses can boost sales and attract new customers with minimal effort.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'User-Friendly Coupon Request Process';
        $data['dedicated_theme_description'] = '<p>The PDF Voucher Module simplifies the process for users to request coupon codes. Customers can easily submit a query for a coupon code directly through the EcommerceGo platform. This intuitive process ensures that customers can take advantage of promotional offers without any hassle, fostering a positive shopping experience and encouraging repeat business.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Admin Control and Approval","dedicated_theme_section_description":"Upon receiving a coupon code request, the module notifies the admin, who can then review and approve the request. EcommerceGo SaaS provides a streamlined interface for admins to manage these requests efficiently. Once approved, the system automatically generates a PDF containing the coupon codes. This automated process minimizes the administrative burden, allowing businesses to focus on other critical aspects of their operations."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Automated PDF Generation and Distribution","dedicated_theme_section_description":" After the admin approves a coupon request, the PDF Voucher Module takes over, generating a professional-looking PDF document with the requested coupon codes. This document is then sent directly to the user’s email. The automation of this process ensures that customers receive their coupon codes promptly, enhancing their satisfaction and encouraging them to make a purchase. The professional format of the PDF also reflects positively on the business, reinforcing its credibility."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhancing Marketing and Customer Engagement","dedicated_theme_section_description":"The PDF Voucher Module is a powerful tool for boosting marketing campaigns and customer engagement. By providing an efficient way to distribute coupon codes, businesses can attract more customers and incentivize purchases. The ability to manage coupon requests and distribution seamlessly allows merchants to run promotions effectively, driving sales and fostering customer loyalty. This module not only simplifies the process but also opens new avenues for creative marketing strategies, helping businesses to grow and succeed in the competitive e-commerce environment."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"PDFVouchers"},{"screenshots":"","screenshots_heading":"PDFVouchers"},{"screenshots":"","screenshots_heading":"PDFVouchers"},{"screenshots":"","screenshots_heading":"PDFVouchers"},{"screenshots":"","screenshots_heading":"PDFVouchers"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'PDFVouchers')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'PDFVouchers'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
