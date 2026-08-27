<?php

namespace Workdo\ProductsPDF\database\seeders;

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
        $data['product_main_heading'] = 'Products PDF';
        $data['product_main_description'] = '<p>In the competitive world of eCommerce, presenting your products in the best light is crucial. The Product PDF Module of eCommerceGo SaaS empowers you to create stunning, professional-grade PDF documents for each of your products. These PDFs can include detailed descriptions, high-resolution images, pricing information, and specifications, providing your customers with a comprehensive overview in an easy-to-read format. By offering downloadable PDFs, you enhance the shopping experience, making it easier for customers to review and share product information.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Accuracy and Effortlessly';
        $data['dedicated_theme_description'] = '<p>Generate and share detailed product PDFs effortlessly with the Product PDF module of eCommerceGo SaaS.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhance Customer Engagement and Retention","dedicated_theme_section_description":"Providing detailed and visually appealing product information can significantly enhance customer engagement and retention. The Product PDF Module allows you to include rich media elements such as images and interactive links within your PDFs, making the documents more engaging. Customers are more likely to spend time reviewing products and making informed purchasing decisions when they have access to high-quality, detailed information. This level of engagement helps build customer trust and loyalty, encouraging repeat business."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Easy Integration and Automation","dedicated_theme_section_description":"Seamlessly integrate the Product PDF Module with your existing eCommerce platform to streamline the creation and distribution of product PDFs. Our module supports automated generation of PDFs based on your product catalog, ensuring that every product page on your website has an up-to-date downloadable PDF. This automation saves you time and effort, allowing you to focus on other critical aspects of your business. Additionally, it ensures that your customers always have access to the latest product information."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Products PDF"},{"screenshots":"","screenshots_heading":"Products PDF"},{"screenshots":"","screenshots_heading":"Products PDF"},{"screenshots":"","screenshots_heading":"Products PDF"},{  "screenshots":"","screenshots_heading":"Products PDF"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'ProductsPDF')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'ProductsPDF'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
