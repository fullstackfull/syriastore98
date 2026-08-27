<?php

namespace Workdo\CountDownTimer\Database\Seeders;

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
        $module = 'CountDownTimer';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Count-Down Timer';
        $data['product_main_description'] = '<p>Elevate your eCommerceGo SaaS platform with the Countdown Timer Module, a dynamic tool designed to create excitement and urgency around your upcoming sales events. With this feature, admins can easily set up countdown timers for sales directly from the store settings page, enhancing customer engagement and boosting sales.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Enhance Customer Experience';
        $data['dedicated_theme_description'] = '<p>The Countdown Timer Module enhances the overall customer experience by providing clear and concise information about upcoming sales events.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Easy Setup and Customization","dedicated_theme_section_description":"Admins can seamlessly configure countdown settings to suit their specific sales events. From the store settings page, they can specify the duration of the countdown, add a title, set the start date and time, provide a description, and even upload custom images for the sale and background. With a range of theme layouts to choose from, admins can customize the look and feel of the countdown timer to match their brand aesthetic."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Drive Sales with Urgency","dedicated_theme_section_description":"By displaying countdown timers on the frontend of your eCommerceGo SaaS platform, you create a sense of urgency that motivates customers to act quickly. As the timer counts down, customers are reminded of the limited time remaining to take advantage of the sale, encouraging them to make a purchase before its too late. This urgency not only drives immediate sales but also increases overall conversion rates."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"CountDownTimer"},{"screenshots":"","screenshots_heading":"CountDownTimer"},{"screenshots":"","screenshots_heading":"CountDownTimer"},{"screenshots":"","screenshots_heading":"CountDownTimer"},{"screenshots":"","screenshots_heading":"CountDownTimer"}]';
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
