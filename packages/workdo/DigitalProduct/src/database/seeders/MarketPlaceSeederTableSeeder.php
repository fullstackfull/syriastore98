<?php

namespace Workdo\DigitalProduct\Database\Seeders;

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
        $module = 'DigitalProduct';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Digital Product';
        $data['product_main_description'] = '<p>Managing your digital products is easy with the eCommerceGo SaaS Digital Product Module. Whether you are a seasoned entrepreneur or just starting, our platform provides the tools you need to organize, showcase, and deliver your digital offerings efficiently. With our module, you can streamline your entire workflow, from adding products to delivering them to your customers.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'User-Friendly Product Addition Process';
        $data['dedicated_theme_description'] = '<p>Adding digital products is simple with our intuitive interface. You can easily input all the relevant details for each product, including titles, descriptions, prices, and any additional information you wish to provide. This allows you to create informative product listings that effectively showcase the value of your digital offerings. Plus, our module supports the upload of attachments, like files or images, to complement your products and enhance the customer experience.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhanced Frontend Accessibility","dedicated_theme_section_description":"Once you have added your digital products, they seamlessly integrate into your storefront. A dedicated column is presented on the front end, giving your customers easy access to all your digital offerings. From here, customers can browse products and download any associated attachments with just a few clicks. This user-friendly interface not only improves the customer experience but also encourages engagement with your digital content."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Robust Backend Management Tools","dedicated_theme_section_description":"On the backend, you have full control over your digital products. Our module provides a comprehensive dashboard where you can view, edit, or delete products as needed. This gives you the flexibility to manage your product catalog according to your business requirements. Additionally, you can download attachments directly from the backend, ensuring you always have access to your digital files. With our robust management tools, you can stay organized and efficient in your digital product operations."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Centralized Product Repository for Easy Access","dedicated_theme_section_description":"<p>With the Digital Product Module, all your digital products are stored in one centralized repository. Whether you are managing a handful of products or a large catalog, you can easily keep track of everything in one convenient location. Our module provides a structured framework for organizing your products, making it easy to find and manage them whenever you need to. Say goodbye to scattered files and folders – with eCommerceGo SaaS, your digital products are always at your fingertips.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"DigitalProduct"},{"screenshots":"","screenshots_heading":"DigitalProduct"},{"screenshots":"","screenshots_heading":"DigitalProduct"},{"screenshots":"","screenshots_heading":"DigitalProduct"},{"screenshots":"","screenshots_heading":"DigitalProduct"}]';
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
