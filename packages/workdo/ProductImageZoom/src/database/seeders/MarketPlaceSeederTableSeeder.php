<?php

namespace Workdo\ProductImageZoom\Database\Seeders;

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
        $module = 'ProductImageZoom';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'ProductImageZoom';
        $data['product_main_description'] = '<p>The Product Image Zoom Add-On for eCommerceGo SaaS takes your online store to the next level by giving customers the ability to inspect your products with precision. This feature allows users to view intricate details of your offerings, building trust and confidence in their purchasing decisions.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2>Showcase Products in Greater Detail</h2>';
        $data['dedicated_theme_description'] = '<p>In online shopping, visuals are everything. The Product Image Zoom add-on enables customers to zoom in and explore your products up close. Whether it’s the texture of fabric, the fine design of jewelry, or the intricate details of a gadget, this feature ensures your products look their best and provide all the visual clarity buyers need.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"User-Friendly and Intuitive","dedicated_theme_section_description":"<p>The zoom functionality is designed to enhance user experience with a smooth, intuitive interface. Customers can hover or click to zoom, making it easy to navigate product images without hassle. This seamless integration improves customer satisfaction and encourages them to explore more of your catalog.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Customizable and Versatile","dedicated_theme_section_description":"<p>When customers can clearly inspect products before purchasing, they are more likely to complete the transaction confidently. By reducing uncertainty, the Product Image Zoom Add-On not only boosts sales but also minimizes product returns, creating a win-win for both your business and your customers.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Drive Conversions and Reduce Returns","dedicated_theme_section_description":"<p>Buttons are critical for guiding users through your store, and their color plays a significant role in user experience. With the Theme Customizer module, you can easily change the button colors to ensure they stand out and encourage clicks. Choose colors that complement your theme or make bold choices to draw attention to key actions, such as adding items to a cart or completing a purchase. Customizing button colors helps improve navigation and enhances the overall functionality of your store, making it more user-friendly and visually cohesive. </p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"ProductImageZoom"},{"screenshots":"","screenshots_heading":"ProductImageZoom"},{"screenshots":"","screenshots_heading":"ProductImageZoom"},{"screenshots":"","screenshots_heading":"ProductImageZoom"}]';
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
