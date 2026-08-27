<?php

namespace Workdo\ProductCatelog\Database\Seeders;

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
        $module = 'ProductCatelog';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Product Catelog';
        $data['product_main_description'] = '<p>Our Product Catalog Module in eCommerceGo SaaS offers a streamlined approach to managing catalog requests. Through our intuitive interface, users can create custom email templates tailored to specific modules, ensuring customers receive relevant product information. With organized lists of requests, businesses can efficiently prioritize and respond to customer inquiries, ultimately enhancing customer satisfaction and engagement.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Streamlined Product Catalogs Management';
        $data['dedicated_theme_description'] = '<p>Product Catalog Module in eCommerceGo SaaS offers a streamlined approach to managing catalog requests and its provide product details related to cateory in email. we can provide product detail through mail in your requested email address.</p>';
        $data['dedicated_theme_sections'] = '[
            {"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Custom Email Templates for Every Module","dedicated_theme_section_description":"Designing personalized email templates is made easy with our module. Whether your eCommerce store sells fashion, electronics, or home goods, you can create templates that resonate with your product range. These templates ensure that customers receive catalogs that are tailored to their interests, increasing the likelihood of conversions. From sleek electronics to trendy fashion, our customizable templates cater to diverse product categories."},
            {"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Efficient Request Tracking and Management","dedicated_theme_section_description":"Track and manage catalog requests effortlessly with our modules comprehensive features. Access a centralized list of requests, organized by module, allowing you to stay on top of customer inquiries. This organized approach ensures timely responses and prevents requests from falling through the cracks. With efficient request management, you can focus on providing excellent customer service and growing your business."},
            {"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Convenient Frontend Request Submission","dedicated_theme_section_description":"Empower your customers with convenient front-end request submission. Our module allows users to easily send catalog requests directly from the frontend interface. This seamless process enhances user experience and encourages customers to explore your product offerings further. By simplifying the request process, you increase customer engagement and satisfaction, ultimately driving sales and revenue."},
            {"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhancing User Experience and Engagement","dedicated_theme_section_description":"The Product Catalog Module doesnot just streamline internal processes; it also enhances user experience and engagement. By providing customers with relevant product catalogs tailored to their interests, you create a more personalized shopping experience. This increases customer satisfaction and encourages repeat purchases. With eCommerceGo SaaS, you can take your eCommerce business to new heights by delivering exceptional service and engagement to your customers. <\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ProductCatelog"},{"screenshots":"","screenshots_heading":"ProductCatelog"},{"screenshots":"","screenshots_heading":"ProductCatelog"},{"screenshots":"","screenshots_heading":"ProductCatelog"},{"screenshots":"","screenshots_heading":"ProductCatelog"}]';
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
