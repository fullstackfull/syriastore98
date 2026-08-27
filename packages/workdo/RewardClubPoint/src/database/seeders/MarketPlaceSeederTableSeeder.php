<?php

namespace Workdo\RewardClubPoint\Database\Seeders;

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
        $module = 'RewardClubPoint';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Reward-Club Point';
        $data['product_main_description'] = '<p>At eCommerceGo, we understand the importance of customer loyalty in todays competitive market. Thats why were excited to introduce our Rewards & Club Points Module, designed to help businesses easily manage their loyalty programs. With this module, you can create customized reward systems tailored to your unique business needs, ensuring your customers feel appreciated and incentivized to keep coming back.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Drive Customer Engagement and Boost Sales';
        $data['dedicated_theme_description'] = '<p>By implementing eCommerceGos Rewards & Club Points Module, you can drive customer engagement and boost sales like never before. Rewarding customers for their purchases, referrals, and engagement not only fosters loyalty but also encourages repeat business and higher spending.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Comprehensive Transaction History","dedicated_theme_section_description":"Understanding your customers behavior is key to optimizing your loyalty program. Thats why our History Order Rewards feature provides a detailed transaction history, allowing you to track and analyze all reward activity. With filtering options and export functionality, you can gain valuable insights into which rewards are most effective and make data-driven decisions to further enhance your program."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Checkout Experience","dedicated_theme_section_description":"We believe in making the checkout process as smooth as possible for your customers. With our Checkout Reward Settings, customers can easily redeem their points for instant discounts, making their shopping experience even more rewarding. Whether they choose to use points exclusively or combine them with cash, our platform offers the flexibility your customers will appreciate."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Reward-Club Point"},{"screenshots":"","screenshots_heading":"Reward-Club Point"},{"screenshots":"","screenshots_heading":"Reward-Club Point"},{"screenshots":"","screenshots_heading":"Reward-Club Point"},{  "screenshots":"","screenshots_heading":"Reward-Club Point"}]';
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
