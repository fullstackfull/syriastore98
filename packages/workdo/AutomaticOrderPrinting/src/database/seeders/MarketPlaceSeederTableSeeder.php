<?php

namespace Workdo\AutomaticOrderPrinting\Database\Seeders;

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
        $module = 'AutomaticOrderPrinting';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Automatic Order Printing';
        $data['product_main_description'] = '<p>The Automatic Order Printing Module of eCommerceGo SaaS allows you to create invoices that are as unique as your brand. With a wide selection of templates and color schemes, you can design invoices that reflect your brands identity and enhance the customer experience. This customization extends to adding your logo and other brand elements, ensuring every invoice reinforces your brand image. The flexibility to choose and modify designs means your invoices can range from simple and clean to detailed and vibrant, catering to different customer preferences.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Instant Printing for Immediate Order Processing';
        $data['dedicated_theme_description'] = '<p>Efficiency is key in eCommerce, and our module delivers by instantly printing order details upon purchase. As soon as an order is confirmed, the invoice is automatically printed, and ready for packing and dispatch. This real-time printing capability eliminates delays associated with manual order processing, ensuring your customers receive their products faster. The immediate documentation also helps maintain accurate records, streamlining your fulfillment process and boosting overall operational efficiency.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Streamlined Order Management","dedicated_theme_section_description":"By automating the order printing process, eCommerceGo module significantly enhances your order management system. The centralized control panel allows you to manage all printing settings easily, from adjusting templates to overseeing the print queue. This streamlined approach not only saves time but also reduces the risk of errors, ensuring each order is accurately documented and processed. With less manual intervention required, your team can focus on other critical aspects of the business, such as customer service and inventory management."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Brand Consistency","dedicated_theme_section_description":"Consistent branding is crucial for building trust and recognition, and our module helps you achieve this through customizable invoices. Every printed order will feature your chosen colors, templates, and branding elements, providing a cohesive look across all customer touchpoints. This consistency not only enhances your professional image but also reinforces your brands presence in the minds of your customers. Whether a customer orders once or multiple times, they will always receive a familiar and professional invoice, boosting their confidence in your business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Effortless Setup and Integration","dedicated_theme_section_description":"<p>Getting started with the Automatic Order Printing Module is straightforward. Simply navigate to the settings within your eCommerceGo dashboard, customize your invoice template, and set your preferences. The module is compatible with various printers and eCommerce platforms, ensuring a seamless integration into your existing workflow. Should you need any assistance, our support team is readily available to help. With this module, you can effortlessly automate your order printing process, allowing you to focus on scaling your business while we handle the details<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"AutomaticOrderPrinting"},{"screenshots":"","screenshots_heading":"AutomaticOrderPrinting"},{"screenshots":"","screenshots_heading":"AutomaticOrderPrinting"},{"screenshots":"","screenshots_heading":"AutomaticOrderPrinting"},{"screenshots":"","screenshots_heading":"AutomaticOrderPrinting"}]';
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
