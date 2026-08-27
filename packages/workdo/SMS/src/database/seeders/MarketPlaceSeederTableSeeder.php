<?php

namespace Workdo\SMS\Database\Seeders;

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
        
        $module = 'SMS';
        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'SMS';
        $data['product_main_description'] = '<p>Experience seamless SMS communication with eCommerceGo SaaS. Organize, send, and track messages effortlessly through trusted SMS gateways like AWS, Twilio, Clockwork, Melipayamak, Kavenegar, and SMS Gateway. </p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Categorize and Conquer: Effective Message Organization';
        $data['dedicated_theme_description'] = '<p>Take control of your communication strategy by categorizing your messages within eCommerceGo SaaS. Whether it`s customer updates, transactional notifications, or promotional announcements, our platform allows you to organize your messages effectively. By categorizing messages, you can ensure that recipients receive relevant and targeted information, enhancing the overall user experience and engagement.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Trusted Providers, Seamless Integration","dedicated_theme_section_description":"Our platform seamlessly integrates with leading SMS gateways, providing you with unparalleled reliability and delivery assurance. Whether you prefer AWS, Twilio, Clockwork, Melipayamak, Kavenegar, or another SMS gateway provider, eCommerceGo SaaS ensures seamless integration for uninterrupted communication. Leverage the infrastructure of trusted providers to ensure that your messages reach their intended recipients without fail."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Efficiency at Your Fingertips: Managing SMS Communication","dedicated_theme_section_description":"With eCommerceGo SaaS, managing your SMS communication is a breeze. Our user-friendly interface empowers you to schedule messages, track delivery status, and analyze performance with ease. From message scheduling to delivery status tracking, our platform provides you with the tools you need to stay in control of your communication strategy. Join the ranks of satisfied users who have revolutionized their communication processes with eCommerceGo SaaS – sign up today and discover a new era of efficiency and effectiveness."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"SMS"},{"screenshots":"","screenshots_heading":"SMS"},{"screenshots":"","screenshots_heading":"SMS"},{"screenshots":"","screenshots_heading":"SMS"},{  "screenshots":"","screenshots_heading":"SMS"}]';
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
