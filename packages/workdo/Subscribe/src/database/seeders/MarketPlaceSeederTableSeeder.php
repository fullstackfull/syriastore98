<?php

namespace Workdo\Subscribe\Database\Seeders;

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
        $module = 'Subscribe';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Subscribe';
        $data['product_main_description'] = '<p>With a sleek and eye-catching design, our Subscribe Popup grabs the attention of your website visitors immediately. Whether browsing your product pages or about to exit your site, the popup ensures they take advantage of the opportunity to subscribe to your newsletter.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Drive Conversions';
        $data['dedicated_theme_description'] = '<p>By encouraging visitors to subscribe to your newsletter, you are not only building your email list but also laying the groundwork for future conversions. With regular updates and exclusive offers delivered directly to their inbox, subscribers are more likely to return to your store and make a purchase.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Build Relationships","dedicated_theme_section_description":"A newsletter is more than just a promotional tool—its a way to connect with your audience on a deeper level. Use your newsletter to share valuable content, insider tips, and behind-the-scenes insights that resonate with your customers and strengthen your brand-customer relationships."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Measure Success","dedicated_theme_section_description":"Our Subscribe Popup module comes equipped with powerful analytics features that allow you to track the performance of your popup in real time. From subscription rates to conversion metrics, you will gain valuable insights that help you refine your marketing strategy and optimize your campaigns for success."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Subscribe"},{"screenshots":"","screenshots_heading":"Subscribe"},{"screenshots":"","screenshots_heading":"Subscribe"},{"screenshots":"","screenshots_heading":"Subscribe"},{"screenshots":"","screenshots_heading":"Subscribe"}]';
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
