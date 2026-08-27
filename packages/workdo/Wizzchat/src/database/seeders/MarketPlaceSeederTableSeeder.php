<?php

namespace Workdo\Wizzchat\Database\Seeders;

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
        $module = 'Wizzchat';

        $data['product_main_banner'] = '';
        $data['product_main_status'] = 'on';
        $data['product_main_heading'] = 'Wizzchat';
        $data['product_main_description'] = '<p>Integrating WizzChat Bot into your eCommerceGo platform is a seamless process that significantly enhances your customer service capabilities. WizzChat is a powerful tool designed to streamline communication between your business and your customers, offering real-time assistance and automated responses. This guide will walk you through the necessary steps to successfully integrate WizzChat into your eCommerceGo settings, ensuring that your customers receive timely and efficient support.</p>';
        $data['product_main_demo_link'] = '#';
        $data['product_main_demo_button_text'] = 'View Live Demo';
        $data['dedicated_theme_heading'] = '<h2>Integrating <b>Wizzchat Module</b> with eCommerceGo Saas</h2>';
        $data['dedicated_theme_description'] = '<p>Integrating Wizzchat with eCommerceGo Saas is a seamless process that enhances your customer support and interaction capabilities.Follow these simple steps to integrate Wizzchat into your eCommerceGo Saas platform.</p>';
        $data['dedicated_theme_sections'] = '[{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Accessing Your eCommerceGo Settings","dedicated_theme_section_description":"To begin, log in to your eCommerceGo dashboard using your administrator credentials. Once logged in, navigate to the settings section, which is typically found in the main menu. The settings section is the control center for your eCommerceGo platform, allowing you to customize various aspects of your online store. Look for a module labeled "Chat Integrations" or specifically for "WizzChat" within this section. This is where you will manage the integration process.","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Obtaining and Pasting the WizzChat Code","dedicated_theme_section_description":"Next, you will need to obtain the integration code from the WizzChat website. Log in to your WizzChat account and navigate to the integration or API settings. Here, you will find a unique code provided by WizzChat specifically for your account. Copy this code to your clipboard. Return to your eCommerceGo settings and locate the WizzChat integration module. In the designated field, paste the copied WizzChat code. This step connects your WizzChat account with your eCommerceGo platform, enabling the chatbot functionality.","dedicated_theme_section_cards":{"1":{"title":null,"description":null}}},{"dedicated_theme_section_image":"","dedicated_theme_section_heading":"Verifying and Utilizing the WizzChat Bot","dedicated_theme_section_description":"Finally, verify that the WizzChat Bot has been successfully integrated by navigating back to your eCommerceGo dashboard. You should now see the WizzChat Bot active in your settings, ready to assist customers. Additionally, you can visit your live site to see the chatbot in action. With WizzChat integrated, your customers can enjoy real-time assistance, improving their overall shopping experience and increasing customer satisfaction. This integration not only boosts your support capabilities but also helps in maintaining a responsive and customer-friendly online store.","dedicated_theme_section_cards":{"2":{"title":null,"description":null}}}]';
        $data['dedicated_theme_sections_heading'] = '';
        $data['screenshots'] = '[{"screenshots":"","screenshots_heading":"Wizzchat"},{"screenshots":"","screenshots_heading":"Wizzchat"},{"screenshots":"","screenshots_heading":"Wizzchat"},{"screenshots":"","screenshots_heading":"Wizzchat"},{"screenshots":"","screenshots_heading":"Wizzchat"}]';
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
