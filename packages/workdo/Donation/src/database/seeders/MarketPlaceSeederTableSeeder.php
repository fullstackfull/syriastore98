<?php

namespace Workdo\Donation\Database\Seeders;

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
        $module = 'Donation';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Donation';
        $data['product_main_description'] = '<p>The Donation Module of eCommerceGo SaaS empowers businesses to make a positive difference by integrating charitable giving into their online platforms. This module enables businesses to align their brand values with social responsibility initiatives, fostering goodwill and making a meaningful impact on causes that matter. By providing customers with the option to donate during the checkout process, businesses can leverage their e-commerce presence to support charitable organizations and contribute to positive social change.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = 'Foster Social Impact Through Online Giving';
        $data['dedicated_theme_description'] = '<p>The Donation module of eCommerceGo SaaS empowers businesses to seamlessly integrate charitable giving into their online platforms, fostering goodwill and social impact with ease.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Seamless Integration for Giving Back","dedicated_theme_section_description":"With the Donation Module, businesses can seamlessly integrate donation options into their checkout flow, providing customers with an effortless way to give back. By incorporating donation prompts at strategic points during the purchasing journey, businesses can raise awareness about important causes and inspire customers to contribute. This frictionless approach to online giving enhances the overall customer experience and demonstrates a commitment to corporate social responsibility."},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Amplify Brand Reputation and Trust","dedicated_theme_section_description":"Supporting charitable causes through the Donation Module not only benefits those in need but also enhances a business brand reputation and trustworthiness. Customers are increasingly drawn to socially conscious brands that prioritize giving back to the community. By showcasing their commitment to philanthropy, businesses can differentiate themselves from competitors and build stronger connections with their audience, fostering loyalty and positive brand associations."}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Donation"},{"screenshots":"","screenshots_heading":"Donation"},{"screenshots":"","screenshots_heading":"Donation"},{"screenshots":"","screenshots_heading":"Donation"},{"screenshots":"","screenshots_heading":"Donation"}]';
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
