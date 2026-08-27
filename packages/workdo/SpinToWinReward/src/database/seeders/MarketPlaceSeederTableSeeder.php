<?php

namespace Workdo\SpinToWinReward\Database\Seeders;

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
        $module = 'SpinToWinReward';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Spin To Win Reward';
        $data['product_main_description'] = '<p>The Spin to Win Reward module of eCommerceGo SaaS revolutionizes customer engagement by introducing an interactive element to the online shopping experience. With this innovative feature, businesses can captivate their audience and incentivize purchases through an exciting spin wheel game. By gamifying the shopping process, the module creates a sense of anticipation and fun, driving increased customer interaction and loyalty.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Engage Customers with Interactive Rewards';
        $data['dedicated_theme_description'] = '<p>The Spin to Win Reward module of eCommerceGo SaaS adds an interactive element to your online store, enticing customers with exciting prizes and incentives through a fun and engaging spin wheel game.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Exciting Prizes and Incentives","dedicated_theme_section_description":"Customers are enticed to participate in the spin wheel game with the promise of exciting prizes and incentives. Whether its discounts, freebies, or exclusive offers, the Spin to Win Reward module offers a variety of rewards to appeal to different preferences and motivations. This element of surprise adds an extra layer of excitement to the shopping journey, encouraging repeat visits and purchases."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Drive Conversions and Sales","dedicated_theme_section_description":"Ultimately, the Spin to Win Reward module is designed to drive conversions and sales for businesses. By leveraging the element of gamification and offering enticing rewards, businesses can encourage customers to complete their purchases and increase their order value. The module not only enhances the shopping experience but also delivers tangible results in terms of revenue growth and customer satisfaction."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SpinToWinReward"},{"screenshots":"","screenshots_heading":"SpinToWinReward"},{"screenshots":"","screenshots_heading":"SpinToWinReward"},{"screenshots":"","screenshots_heading":"SpinToWinReward"},{"screenshots":"","screenshots_heading":"SpinToWinReward"}]';
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
