<?php

namespace Workdo\ProductBadgeManagement\Database\Seeders;

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

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Product Badge Management';
        $data['product_main_description'] = '<p>The Product Badge Management module of eCommerceGo SaaS is designed to enhance the visibility and appeal of your products through the strategic use of badges. These badges can take the form of both images and text, providing a versatile way to highlight key product features, promotions, and unique selling points. By incorporating eye-catching badges, you can draw attention to specific products, boost customer engagement, and drive sales.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Easy Badge Creation and Customization';
        $data['dedicated_theme_description'] = '<p>Creating and customizing product badges is a breeze with our intuitive interface. You can effortlessly design badges that align with your brands aesthetics and marketing goals. Whether you want to emphasize a "New Arrival," "Best Seller," "Limited Edition," or any other key attribute, our module allows you to tailor the text and imagery to perfectly match your promotional strategy. This flexibility ensures that your product badges are not only visually appealing but also highly effective in communicating your messages.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration with Your Store","dedicated_theme_section_description":"Once created, product badges are seamlessly integrated into your storefront. They appear prominently on product images, ensuring that they capture the attention of your customers as they browse through your offerings. This seamless integration means that there is no disruption to the shopping experience; instead, it enhances it by providing clear, concise information at a glance. This not only aids in the decision-making process but also adds a professional touch to your product listings."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Robust Backend Management","dedicated_theme_section_description":"Managing your product badges from the backend is both simple and efficient. The Product Badge Management module offers a comprehensive dashboard where you can easily add, edit, or remove badges as needed. This centralized management system allows you to keep your badges up-to-date and relevant to your current promotions and inventory status. Additionally, the module supports bulk actions, enabling you to apply badges to multiple products simultaneously, saving you time and effort."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Boosting Sales and Customer Engagement","dedicated_theme_section_description":"<p>The strategic use of product badges can significantly boost sales and enhance customer engagement. By clearly highlighting product features, promotions, and unique selling points, badges help guide customers towards making informed purchasing decisions. This increased visibility can lead to higher conversion rates and improved overall shopping experiences. Furthermore, well-designed badges add a layer of professionalism to your store, helping to build trust and credibility with your customers.<\/p>"}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Badge"},{"screenshots":"","screenshots_heading":"Badge"},{"screenshots":"","screenshots_heading":"Badge"},{"screenshots":"","screenshots_heading":"Badge"},{"screenshots":"","screenshots_heading":"Badge"}]';
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
            if(!MarketplacePageSetting::where('name', '=', $key)->where('module', '=', 'Badge')->exists()){
                MarketplacePageSetting::updateOrCreate(
                [
                    'name' => $key,
                    'module' => 'ProductBadgeManagement'

                ],
                [
                    'value' => $value
                ]);
            }
        }
    }
}
