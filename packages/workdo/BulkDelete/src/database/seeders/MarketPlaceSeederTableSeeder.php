<?php

namespace Workdo\BulkDelete\Database\Seeders;

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
        $module = 'BulkDelete';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'BulkDelete';
        $data['product_main_description'] = '<p>The Bulk Delete Module is designed to streamline the process of managing your inventory by allowing you to remove multiple items at once. This feature is ideal for businesses that regularly need to update or clean up their product listings, saving time and reducing manual effort. With just a few clicks, you can clear out outdated, discontinued, or irrelevant products from your stores catalog.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'User-Friendly and Efficient';
        $data['dedicated_theme_description'] = '<p>Using this module is straightforward and user-friendly, ensuring that even those with limited technical expertise can efficiently manage their inventory. The intuitive interface guides you through selecting the products you want to delete, whether it\'s a specific category, a collection of discontinued items, or products that haven\'t been performing well.</p>';
        $data['dedicated_theme_sections'] = '[
            {
                "dedicated_theme_section_image": "",
                "dedicated_theme_section_heading": "Fast and Accurate Deletions",
                "dedicated_theme_section_description": "Once products are selected, the Bulk Delete Module quickly processes the request, making changes in real-time. This feature is particularly beneficial for large eCommerce stores with extensive inventories, where manually deleting items can be time-consuming and error-prone. The speed and accuracy of bulk deletion ensure that your store is always up to date with minimal effort."
            },
            {
                "dedicated_theme_section_image": "",
                "dedicated_theme_section_heading": "Flexible Deletion Options",
                "dedicated_theme_section_description": "One of the key advantages of this module is its flexibility. You can choose to delete products based on various filters, such as categories, tags, or custom attributes, giving you precise control over which items are removed. This helps prevent accidental deletions while offering a comprehensive solution for managing your product catalog efficiently."
            },
            {
                "dedicated_theme_section_image": "",
                "dedicated_theme_section_heading": "Maintain a Clean and Organized Store",
                "dedicated_theme_section_description": "The Bulk Delete Module not only improves workflow but also helps maintain a clutter-free, professional store layout. By regularly removing products that no longer align with your brand or sales goals, you keep your store clean and organized, enhancing the overall customer experience."
            }
        ]';
        
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"BulkDelete"},{"screenshots":"","screenshots_heading":"BulkDelete"},{"screenshots":"","screenshots_heading":"BulkDelete"},{"screenshots":"","screenshots_heading":"BulkDelete"},{"screenshots":"","screenshots_heading":"BulkDelete"}]';
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
