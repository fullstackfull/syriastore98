<?php

namespace Workdo\HubSpot\Database\Seeders;

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
        $module = 'HubSpot';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'HubSpot';
        $data['product_main_description'] = '<p>HubSpot is an integrated customer platform that empowers scaling businesses to provide exceptional customer experiences. It offers a cohesive solution, combining powerful tools that work seamlessly together. With HubSpot, the focus is on prioritizing customers, ensuring easy customization, and fostering team collaboration through its Smart CRM. Ultimately, HubSpot helps businesses create delightful customer interactions.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Effortless Data Synchronization with HubSpot CRM';
        $data['dedicated_theme_description'] = '<p>Streamline your sales process with HubSpot CRM\'s seamless data synchronization feature. Keep your HubSpot data up-to-date and in sync, ensuring accurate and real-time information across your sales team. Boost efficiency, improve collaboration, and close data faster with this powerful functionality.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Benefits of Using HubSpot CRM for Managing Data","dedicated_theme_section_description":"<p>HubSpot CRM streamlines data management with features like data tracking, sales automation, and pipeline visibility. It offers real-time updates on data progress, simplifies communication with prospects, and enhances sales team productivity. Plus, its integration with other HubSpot tools ensures a comprehensive approach to managing sales data, leading to improved conversions and revenue growth.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"How Does It Work?","dedicated_theme_section_description":"<p>Ever wondered how to effortlessly integrate your HubSpot CRM data with website? Our module simplifies the process by syncing data directly from HubSpot CRM to your website . Easily add and manage your data, streamlining your workflow and boosting productivity. Discover the power of seamless data synchronization today.</p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Enhance Marketing Efforts with HubSpot\'s All-in-One Solution","dedicated_theme_section_description":"<p>HubSpot\'s integrated platform goes beyond CRM, empowering businesses with a suite of marketing tools designed to drive growth. From creating engaging email campaigns to managing social media interactions and tracking performance analytics, HubSpot offers everything needed to execute effective marketing strategies. Its intuitive interface and automation capabilities ensure that businesses can reach the right audience at the right time, fostering stronger connections and boosting brand loyalty. </p>","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"HubSpot"},{"screenshots":"","screenshots_heading":"HubSpot"},{"screenshots":"","screenshots_heading":"HubSpot"},{"screenshots":"","screenshots_heading":"HubSpot"},{"screenshots":"","screenshots_heading":"HubSpot"}]';
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
