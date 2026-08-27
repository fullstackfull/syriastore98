<?php

namespace Workdo\LotteryPrizes\Database\Seeders;

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
        $module = 'LotteryPrizes';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'LotteryPrizes Add-On for E-commerceGo';
        $data['product_main_description'] = '<p>Boost engagement and sales on your platform with the LotteryPrizes Add-On. This exciting feature allows you to create customizable lottery campaigns, where customers can win exclusive prizes, discounts, or rewards while shopping.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Engage Your Customers';
        $data['dedicated_theme_description'] = '<p>Turn casual shoppers into loyal customers by offering fun, interactive lottery events. With LotteryPrizes, you can add a layer of excitement to the shopping experience, encouraging repeat visits and boosting user engagement.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Customizable Campaigns","dedicated_theme_section_description":"Design lotteries that suit your business goals. Set prize types, eligibility criteria, and the duration of each campaign with ease. Whether it’s a seasonal sale or a promotional event, the flexibility of this add-on makes it perfect for all occasions."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Drive More Sales","dedicated_theme_section_description":"Incentivize purchases by offering entry into lotteries for every product bought. This not only increases sales but also enhances customer satisfaction, as they enjoy added value with every transaction."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"LotteryPrizes"},{"screenshots":"","screenshots_heading":"LotteryPrizes"},{"screenshots":"","screenshots_heading":"LotteryPrizes"},{"screenshots":"","screenshots_heading":"LotteryPrizes"},{  "screenshots":"","screenshots_heading":"LotteryPrizes"}]';
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
